#!/usr/bin/env python3
"""Find and remove modules injected into iOS app archives (IPA).

  analyze IPA
      JSON on stdout: the app, every injected module (matched by a reviewed rule, or an
      appended load command whose binary uses no symbol from it), encrypted components,
      metadata problems, and what can be removed safely.
  clean IPA OUTPUT [--recommended] [--remove PATH]... [--remove-extension PATH]...
                   [--opt-in RULE]... [--fix-metadata] [--report FILE]
      Writes a cleaned copy, verifies it, and prints the report. Nothing is removed
      unless it is selected; --recommended selects the reviewed promotions, the pinned
      library patches and the metadata fixes.
  batch DIRECTORY --report FILE [--apply] [--only NAME]... [--opt-in RULE]...
      The recommended cleanup for every IPA in a folder. --apply replaces the files
      atomically and keeps the originals in a private backup folder next to it.

No bundled code is executed. A load command is removed only when it comes after every
library ordinal its binary references, so the code, data and imports of the remaining
binaries keep their bytes, and untouched archive records are copied verbatim. Library
patches apply only to the exact reviewed build. Every output must be signed again for its
device. Exit status: 0 done, 2 refused (the JSON says why), 1 unexpected error.
"""
import argparse
import copy
import hashlib
import json
import os
import plistlib
import posixpath
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import time
import zipfile
from collections import Counter, defaultdict
from pathlib import Path, PurePosixPath

TOOL_VERSION = "2026.10.2"
DEFAULT_RULES = Path(__file__).resolve().with_name("rules.json")

LOADS = {0xC, 0x80000018, 0x8000001F, 0x80000023, 0x20}
MAGICS = {
    b"\xce\xfa\xed\xfe": ("<", False), b"\xcf\xfa\xed\xfe": ("<", True),
    b"\xfe\xed\xfa\xce": (">", False), b"\xfe\xed\xfa\xcf": (">", True),
}
FAT = {b"\xca\xfe\xba\xbe": (">", False), b"\xbe\xba\xfe\xca": ("<", False),
       b"\xca\xfe\xba\xbf": (">", True), b"\xbf\xba\xfe\xca": ("<", True)}
TELEGRAM_LINK = re.compile(rb"https?://t\.me/[A-Za-z0-9_+/-]{3,64}")


class Refused(ValueError):
    """A reason not to touch the archive; the source stays as it is."""


def load_rules(path=DEFAULT_RULES):
    rules = json.loads(Path(path).read_text(encoding="utf-8"))
    for rule in rules.get("library_patches", []):
        rule["sites"] = [tuple(site) for site in rule["sites"]]
    return rules


def digest(path):
    h = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def valid_path(name):
    path = PurePosixPath(name)
    if path.is_absolute() or ".." in path.parts or "\\" in name or "\x00" in name:
        raise Refused("Unsafe archive path: " + name)


def version_tuple(text):
    parts = []
    for part in str(text).split("."):
        if not part.isdigit():
            break
        parts.append(int(part))
    return tuple(parts + [0] * (3 - len(parts)))[:3]


def version_text(value):
    major, minor, patch = value >> 16, (value >> 8) & 0xFF, value & 0xFF
    return f"{major}.{minor}" + (f".{patch}" if patch else "")


# ---------------------------------------------------------------- Mach-O

def leb(data, pos, end):
    value = shift = 0
    while pos < end and shift < 70:
        byte = data[pos]
        pos += 1
        value |= (byte & 127) << shift
        if not byte & 128:
            return value, pos
        shift += 7
    raise ValueError("Malformed LEB128")


def slices(data):
    if data[:4] in MAGICS:
        return [(0, len(data))]
    if data[:4] not in FAT:
        return []
    endian, fat64 = FAT[data[:4]]
    count = struct.unpack_from(endian + "I", data, 4)[0]
    if count > 64:
        raise ValueError("Invalid FAT architecture count")
    result = []
    for i in range(count):
        at = 8 + i * (32 if fat64 else 20)
        fields = struct.unpack_from(endian + ("IIQQII" if fat64 else "IIIII"), data, at)
        start, size = fields[2:4]
        if start < 8 + count * (32 if fat64 else 20) or start + size > len(data):
            raise ValueError("Invalid FAT slice bounds")
        if any(start < old + length and old < start + size for old, length in result):
            raise ValueError("Overlapping FAT slices")
        result.append((start, size))
    return result


class MachO:
    def __init__(self, data):
        self.data = data
        if data[:4] not in MAGICS:
            raise ValueError("Not a Mach-O slice")
        self.endian, self.is64 = MAGICS[data[:4]]
        self.header = 32 if self.is64 else 28
        self.count, self.command_bytes = self.unpack("II", 16)
        if self.count > 8192 or self.header + self.command_bytes > len(data):
            raise ValueError("Invalid Mach-O load command bounds")
        self.commands = []
        self.libraries = []
        self.install_name = None
        pos = self.header
        for _ in range(self.count):
            cmd, size = self.unpack("II", pos)
            if size < 8 or size % 4 or pos + size > self.header + self.command_bytes:
                raise ValueError("Invalid Mach-O command")
            name = None
            if cmd in LOADS or cmd == 0xD:  # LC_ID_DYLIB has the same layout
                offset, = self.unpack("I", pos + 8)
                if not 24 <= offset < size:
                    raise ValueError("Invalid dylib name offset")
                if cmd == 0xD:
                    self.install_name = self.string(pos + offset, pos + size)
                else:
                    name = self.string(pos + offset, pos + size)
                    self.libraries.append(name)
            self.commands.append((cmd, pos, size, name))
            pos += size
        if pos != self.header + self.command_bytes:
            raise ValueError("Mach-O command size mismatch")

    def unpack(self, fmt, pos):
        return struct.unpack_from(self.endian + fmt, self.data, pos)

    def span(self, offset, size):
        if offset < 0 or size < 0 or offset + size > len(self.data):
            raise ValueError("Linkedit range outside Mach-O")
        return offset, offset + size

    def string(self, pos, end):
        at = self.data.find(b"\x00", pos, end)
        if at < 0:
            raise ValueError("Unterminated Mach-O string")
        return self.data[pos:at].decode("utf-8")

    def encrypted(self):
        # LC_ENCRYPTION_INFO(_64): cryptid follows cryptoff and cryptsize.
        return any(self.unpack("I", at + 16)[0] != 0 for cmd, at, _, _ in self.commands if cmd in (0x21, 0x2C))

    def min_os(self):
        for cmd, at, _, _ in self.commands:
            if cmd == 0x32:  # LC_BUILD_VERSION: platform, minos
                platform, minos = self.unpack("II", at + 8)
                if platform in (2, 7):  # iOS, iOS simulator
                    return minos
            if cmd == 0x25:  # LC_VERSION_MIN_IPHONEOS
                return self.unpack("I", at + 8)[0]
        return None

    def bind_ordinals(self, offset, size):
        pos, end = self.span(offset, size)
        refs = []
        while pos < end:
            byte = self.data[pos]
            pos += 1
            op, imm = byte & 0xF0, byte & 0xF
            if op == 0x10:
                refs.append(imm)
            elif op == 0x20:
                value, pos = leb(self.data, pos, end)
                refs.append(value)
            elif op == 0x40:
                name = self.string(pos, end)
                pos += len(name.encode("utf-8")) + 1
            elif op in (0x60, 0x70, 0x80, 0xA0):
                _, pos = leb(self.data, pos, end)
            elif op == 0xC0:
                _, pos = leb(self.data, pos, end)
                _, pos = leb(self.data, pos, end)
            elif op == 0xD0 and imm == 0:
                _, pos = leb(self.data, pos, end)
            elif op not in (0, 0x30, 0x50, 0x90, 0xB0) and not (op == 0xD0 and imm == 1):
                raise ValueError("Unsupported bind opcode " + hex(byte))
        return refs

    def export_ordinals(self, offset, size):
        if not size:
            return []
        _, end = self.span(offset, size)
        refs, visited, pending = [], set(), [0]
        while pending:
            relative = pending.pop()
            if relative in visited:
                continue
            visited.add(relative)
            if len(visited) > size or not 0 <= relative < size:
                raise ValueError("Invalid export trie")
            pos = offset + relative
            terminal, pos = leb(self.data, pos, end)
            next_pos = pos + terminal
            if next_pos >= end:
                raise ValueError("Invalid export terminal")
            if terminal:
                flags, cursor = leb(self.data, pos, next_pos)
                if flags & 8:
                    ordinal, _ = leb(self.data, cursor, next_pos)
                    refs.append(ordinal)
            count = self.data[next_pos]
            cursor = next_pos + 1
            for _ in range(count):
                edge = self.string(cursor, end)
                cursor += len(edge.encode("utf-8")) + 1
                child, cursor = leb(self.data, cursor, end)
                pending.append(child)
        return refs

    def ordinals(self):
        """Library ordinals the binary binds or re-exports symbols from."""
        refs = []
        for cmd, at, _, _ in self.commands:
            if cmd == 0x16:
                raise ValueError("Two-level hints require a separate rewrite")
            if cmd in (0x22, 0x80000022):
                fields = self.unpack("10I", at + 8)
                for i in (2, 4, 6):
                    refs.extend(self.bind_ordinals(fields[i], fields[i + 1]))
                refs.extend(self.export_ordinals(fields[8], fields[9]))
            elif cmd == 0x80000033:
                refs.extend(self.export_ordinals(*self.unpack("II", at + 8)))
            elif cmd == 0x80000034:
                offset, size = self.unpack("II", at + 8)
                _, end = self.span(offset, size)
                version, _, imports, _, count, fmt, _ = self.unpack("7I", offset)
                if version != 0 or fmt not in (1, 2, 3):
                    raise ValueError("Unsupported chained import table")
                stride = {1: 4, 2: 8, 3: 16}[fmt]
                if offset + imports + count * stride > end:
                    raise ValueError("Invalid chained import table bounds")
                for i in range(count):
                    value, = self.unpack("Q" if fmt == 3 else "I", offset + imports + i * stride)
                    ordinal = value & (65535 if fmt == 3 else 255)
                    if ordinal < (65520 if fmt == 3 else 240):
                        refs.append(ordinal)
            elif cmd == 2:
                offset, count, _, _ = self.unpack("4I", at + 8)
                stride = 16 if self.is64 else 12
                self.span(offset, count * stride)
                for i in range(count):
                    entry = offset + i * stride
                    kind = self.data[entry + 4]
                    desc, = self.unpack("H", entry + 6)
                    if not kind & 0xE0 and kind & 0xE == 0:
                        ordinal = desc >> 8
                        if ordinal < 254:
                            refs.append(ordinal)
        return refs

    def remove(self, unwanted):
        """Drops the load commands whose name `unwanted` accepts, if no import can move."""
        selected, ordinal = [], 0
        for command in self.commands:
            cmd, _, _, name = command
            if cmd in LOADS:
                ordinal += 1
                if unwanted(name):
                    selected.append((ordinal, command))
        if not selected:
            return self.data, []
        # Injections are appended beyond all real imports. Rather than rewriting binding
        # tables, require that invariant for every slice.
        referenced = self.ordinals()
        if any(n >= min(n for n, _ in selected) for n in referenced):
            raise Refused("Removal could change a referenced library ordinal")
        removed = {at for _, (_, at, _, _) in selected}
        commands = b"".join(self.data[at:at + size] for _, at, size, _ in self.commands if at not in removed)
        result = bytearray(self.data)
        struct.pack_into(self.endian + "II", result, 16, self.count - len(selected), len(commands))
        result[self.header:self.header + self.command_bytes] = commands + bytes(self.command_bytes - len(commands))
        check = MachO(bytes(result))
        if check.ordinals() != referenced or result[self.header + self.command_bytes:] != self.data[self.header + self.command_bytes:]:
            raise Refused("Mach-O payload or imports changed")
        return bytes(result), [c[3] for _, c in selected]


def remove_loads(data, unwanted):
    result = bytearray(data)
    removed = []
    for offset, size in slices(data):
        updated, loads = MachO(data[offset:offset + size]).remove(unwanted)
        result[offset:offset + size] = updated
        removed.extend(loads)
    return bytes(result), sorted(set(removed))


def patch_library(name, data, rules):
    """Applies the reviewed instruction patch for this exact build; idempotent."""
    spec = next((rule for rule in rules.get("library_patches", []) if rule["name"] == PurePosixPath(name).name), None)
    if spec is None:
        return data, None
    current_hash = hashlib.sha256(data).hexdigest()
    if current_hash == spec["patched_sha256"]:
        return data, None
    if current_hash != spec["original_sha256"]:
        raise Refused("Unsupported library build; manual review required: " + name)
    result = bytearray(data)
    sites = []
    for offset, expected_hex, purpose in spec["sites"]:
        expected = bytes.fromhex(expected_hex)
        if offset < 0 or offset % 4 or len(expected) != 4 or data[offset:offset + 4] != expected:
            raise Refused("Library instruction precondition failed: " + name)
        result[offset:offset + 4] = bytes.fromhex("c0035fd6")  # ARM64 ret
        sites.append({"offset": offset, "before": expected_hex, "after": "c0035fd6", "purpose": purpose})
    result = bytes(result)
    if hashlib.sha256(result).hexdigest() != spec["patched_sha256"]:
        raise Refused("Patched library hash mismatch: " + name)
    return result, {"rule": spec["id"], "reason": spec["reason"], "source_sha256": current_hash,
                    "output_sha256": spec["patched_sha256"], "sites": sites}


# ---------------------------------------------------------------- Archive

def rewrite_zip(source, destination, removals, replacements):
    # Copy untouched local ZIP records verbatim; only modified entries are recompressed.
    # This preserves resource bytes, modes and original compression.
    with zipfile.ZipFile(source) as old, Path(source).open("rb") as raw, zipfile.ZipFile(destination, "w", allowZip64=True) as new:
        entries = sorted(old.infolist(), key=lambda i: i.header_offset)
        for index, entry in enumerate(entries):
            if entry.filename in removals:
                continue
            if entry.filename in replacements:
                new.writestr(copy.copy(entry), replacements[entry.filename], compresslevel=6)
                continue
            finish = entries[index + 1].header_offset if index + 1 < len(entries) else old.start_dir
            raw.seek(entry.header_offset)
            new.fp.seek(new.start_dir)
            item = copy.copy(entry)
            item.header_offset = new.fp.tell()
            left = finish - entry.header_offset
            if left < 0:
                raise Refused("Invalid ZIP record order")
            while left:
                chunk = raw.read(min(left, 1024 * 1024))
                if not chunk:
                    raise Refused("Truncated ZIP record")
                new.fp.write(chunk)
                left -= len(chunk)
            new.start_dir = new.fp.tell()
            new.filelist.append(item)
            new.NameToInfo[item.filename] = item
            new._didModify = True
        new.comment = old.comment


def is_rar(path):
    with Path(path).open("rb") as stream:
        return stream.read(7).startswith(b"Rar!")


def convert_rar(source, destination):
    """Some sources ship a RAR renamed to .ipa; the app inside is kept as it is."""
    if shutil.which("bsdtar") is None:
        raise Refused("RAR archive: install bsdtar (libarchive-tools) to convert it")
    listed = subprocess.run(["bsdtar", "-tf", str(source)], check=True, capture_output=True, text=True).stdout.splitlines()
    for name in listed:
        valid_path(name)
    verbose = subprocess.run(["bsdtar", "-tvf", str(source)], check=True, capture_output=True, text=True).stdout.splitlines()
    if any(line and line[0] not in ("-", "d") for line in verbose):
        raise Refused("RAR links or special files require manual review")
    with tempfile.TemporaryDirectory(prefix="ipa-rar-") as temporary:
        subprocess.run(["bsdtar", "-xf", str(source), "-C", temporary], check=True, capture_output=True)
        root = Path(temporary)
        with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
            for path in sorted(root.rglob("*")):
                if path.is_symlink():
                    raise Refused("Unexpected RAR symlink")
                archive.write(path, path.relative_to(root).as_posix())


# ---------------------------------------------------------------- Analysis

class Bundle:
    """One app archive: its binaries, what loads what, and what is injected."""

    def __init__(self, archive, rules):
        self.rules = rules
        entries = archive.infolist()
        names = [entry.filename for entry in entries]
        if len(set(names)) != len(names):
            raise Refused("Duplicate ZIP entry names")
        for entry in entries:
            valid_path(entry.filename)
            if entry.flag_bits & 1:
                raise Refused("Encrypted ZIP entry")
        self.entries = {entry.filename: entry for entry in entries}
        infos = [n for n in names if n.startswith("Payload/") and n.count("/") == 2 and n.endswith("/Info.plist")]
        if len(infos) != 1:
            raise Refused("Expected exactly one app in Payload/")
        self.info_path = infos[0]
        self.root = self.info_path.rsplit("/", 1)[0] + "/"
        self.info_raw = archive.read(self.info_path)
        self.info = plistlib.loads(self.info_raw)
        self.main = self.root + str(self.info.get("CFBundleExecutable", ""))

        self.binaries = {}
        for entry in entries:
            if entry.is_dir() or entry.file_size < 4:
                continue
            with archive.open(entry) as stream:
                magic = stream.read(4)
            if magic in MAGICS or magic in FAT:
                self.binaries[entry.filename] = archive.read(entry)
        if self.main not in self.binaries:
            raise Refused("Main executable missing: " + self.main)

        self.extensions = {}
        pattern = re.compile("^(" + re.escape(self.root) + r"PlugIns/[^/]+\.appex/)Info\.plist$")
        for name in names:
            match = pattern.match(name)
            if match:
                info = plistlib.loads(archive.read(name))
                self.extensions[match.group(1)] = {
                    "bundle_id": info.get("CFBundleIdentifier"),
                    "executable": match.group(1) + str(info.get("CFBundleExecutable", "")),
                    "point": (info.get("NSExtension") or {}).get("NSExtensionPointIdentifier"),
                }

        self.parsed = {}
        # dyld also matches a load against the install names of images already loaded:
        # injected tweaks reach a bundled substrate through its absolute install name.
        self.install_names = {}
        for path, data in self.binaries.items():
            try:
                self.parsed[path] = [MachO(data[o:o + s]) for o, s in slices(data)]
            except (ValueError, struct.error) as error:
                self.parsed[path] = Refused(f"{path}: {error}")
                continue
            for macho in self.parsed[path]:
                if macho.install_name:
                    self.install_names.setdefault(macho.install_name, path)

        # target -> one row per (binary, slice) that loads it
        self.loaded_by = defaultdict(list)
        for binary, parsed in self.parsed.items():
            if isinstance(parsed, Exception):
                continue
            for macho in parsed:
                try:
                    referenced = max(macho.ordinals(), default=0)
                    problem = None
                except (ValueError, struct.error) as error:
                    referenced, problem = None, str(error)
                for ordinal, name in enumerate(macho.libraries, 1):
                    target = self.resolve(binary, name)
                    if target is not None:
                        self.loaded_by[target].append({"binary": binary, "load": name, "ordinal": ordinal,
                                                       "unused": referenced is not None and ordinal > referenced, "problem": problem})

    def exec_dir(self, binary):
        # Code in an extension runs in the extension's own process.
        for root in self.extensions:
            if binary.startswith(root):
                return root
        return self.root

    def resolve(self, binary, load):
        """The archive path a load command points at, or None for system libraries."""
        here = posixpath.dirname(binary) + "/"
        if load.startswith("@executable_path/"):
            candidates = [self.exec_dir(binary) + load[17:]]
        elif load.startswith("@loader_path/"):
            candidates = [here + load[13:]]
        elif load.startswith("@rpath/"):
            rest, base = load[7:], self.exec_dir(binary)
            candidates = [base + "Frameworks/" + rest, here + "Frameworks/" + rest, self.root + "Frameworks/" + rest, here + rest, base + rest]
        else:
            candidates = []
        for candidate in candidates:
            candidate = posixpath.normpath(candidate)
            if candidate in self.binaries:
                return candidate
        # A system path or an unresolved one may still name a bundled image by its install name.
        target = self.install_names.get(load)
        return target if target != binary else None

    def container(self, path):
        """What goes with a module: the whole framework when it is the framework's own binary."""
        directory, name = posixpath.split(path)
        if directory.endswith(".framework") and posixpath.basename(directory)[:-len(".framework")] == name:
            return directory + "/"
        return path

    def entries_under(self, container):
        if not container.endswith("/"):
            return {container}
        return {name for name in self.entries if name.startswith(container)}

    def executables(self):
        return {self.main} | {extension["executable"] for extension in self.extensions.values()}

    def encrypted(self, path):
        parsed = self.parsed.get(path)
        return not isinstance(parsed, Exception) and parsed is not None and any(macho.encrypted() for macho in parsed)

    def markers(self, data, names):
        return [marker for marker in names if marker.encode() in data]

    def match_rule(self, path, data):
        base = PurePosixPath(path).name
        for rule in self.rules.get("modules", []):
            if base in rule["names"] and all(marker.encode() in data for marker in rule["all_markers"]):
                return rule
        return None

    def modules(self):
        names = Counter(PurePosixPath(path).name for path in self.binaries)
        rule_names = {name for rule in self.rules.get("modules", []) for name in rule["names"]}
        executables = self.executables()
        found = []
        for path, data in sorted(self.binaries.items()):
            if path in executables:
                continue
            loaders = self.loaded_by.get(path, [])
            # Injected = loaded only for its constructors: no binary uses a symbol from it.
            unused = bool(loaders) and all(row["unused"] for row in loaders)
            # An encrypted component blocks publication; it is listed so it can be dropped.
            encrypted = self.encrypted(path)
            rule = self.match_rule(path, data) if PurePosixPath(path).name in rule_names else None
            if not rule and not unused and not encrypted:
                continue
            hooks = self.markers(data, self.rules.get("hook_markers", []))
            promos = self.markers(data, self.rules.get("promo_markers", []))
            outside_frameworks = "/Frameworks/" not in "/" + path[len(self.root):]
            if not rule and not encrypted and not (hooks or promos or outside_frameworks):
                continue
            category = (rule["category"] if rule else "encrypted-component" if encrypted else "hook-runtime" if self.hook_runtime(path)
                        else "suspected-hook" if hooks else "suspected-promotion" if promos else "unreferenced-library")
            blocked = None
            if not loaders:
                if not encrypted:
                    blocked = "Not loaded by any binary; needs manual review"
            elif any(row["problem"] for row in loaders):
                blocked = "A binary that loads it cannot be checked: " + next(row["problem"] for row in loaders if row["problem"])
            elif not unused:
                blocked = "A binary uses symbols from it"
            elif names[PurePosixPath(path).name] > 1:
                blocked = "Another file in the app has the same name"
            container = self.container(path)
            found.append({
                "path": path,
                "remove_paths": sorted(self.entries_under(container)),
                "bytes": len(data),
                "sha256": hashlib.sha256(data).hexdigest(),
                "rule": rule["id"] if rule else None,
                "category": category,
                "reason": rule["reason"] if rule else None,
                "recommended": bool(rule and rule.get("recommend")) and blocked is None,
                "removable": blocked is None,
                "blocked_reason": blocked,
                "encrypted": encrypted,
                "loaded_by": sorted({row["binary"] for row in loaders}),
                "hook_markers": hooks,
                "telegram_links": sorted({link.decode() for link in TELEGRAM_LINK.findall(data)})[:10],
                "other_markers": [marker for marker in promos if marker != "https://t.me/"],
            })
        return found

    def hook_runtime(self, path):
        """A bundled substrate/ElleKit: tweaks look it up at runtime (dlsym), invisible to load commands."""
        parsed = self.parsed.get(path)
        installs = [] if isinstance(parsed, Exception) or not parsed else [macho.install_name for macho in parsed if macho.install_name]
        # The runtime's own name; tweaks merely live under /Library/MobileSubstrate/DynamicLibraries/.
        names = {PurePosixPath(path).name} | {PurePosixPath(name).name for name in installs}
        return any(re.fullmatch(r"(?i)cydiasubstrate|libsubstrate\.dylib|libellekit\.dylib|ellekit", name) for name in names)

    def library_patches(self):
        rows = []
        for path, data in sorted(self.binaries.items()):
            for rule in self.rules.get("library_patches", []):
                if PurePosixPath(path).name != rule["name"]:
                    continue
                sha = hashlib.sha256(data).hexdigest()
                state = "applicable" if sha == rule["original_sha256"] else "already-patched" if sha == rule["patched_sha256"] else "unknown-build"
                rows.append({"path": path, "rule": rule["id"], "reason": rule["reason"], "state": state})
        return rows

    def binary_min_os(self):
        parsed = self.parsed.get(self.main)
        if isinstance(parsed, Exception) or not parsed:
            return None
        values = [value for value in (macho.min_os() for macho in parsed) if value is not None]
        return version_text(max(values)) if values else None

    def metadata_issues(self):
        issues = []
        bundle_id = str(self.info.get("CFBundleIdentifier", ""))
        if bundle_id.endswith("."):
            issues.append({"code": "BUNDLE_ID_TRAILING_DOT", "value": bundle_id, "fix": bundle_id.rstrip(".")})
        plist_min, binary_min = self.info.get("MinimumOSVersion"), self.binary_min_os()
        if plist_min and binary_min and version_tuple(plist_min) < version_tuple(binary_min):
            issues.append({"code": "MINIMUM_OS_BELOW_BINARY", "value": str(plist_min), "fix": binary_min})
        return issues

    def extension_rows(self):
        rows = []
        for root, extension in sorted(self.extensions.items()):
            binaries = [path for path in self.binaries if path.startswith(root)]
            rows.append({"path": root, "bundle_id": extension["bundle_id"], "point": extension["point"],
                         "encrypted": any(self.encrypted(path) for path in binaries)})
        return rows

    def report(self):
        modules = self.modules()
        patches = self.library_patches()
        issues = self.metadata_issues()
        main = self.binaries[self.main]
        return {
            "tool_version": TOOL_VERSION,
            "rules_version": self.rules.get("version"),
            "app": {
                "bundle_id": self.info.get("CFBundleIdentifier"),
                "version": self.info.get("CFBundleShortVersionString"),
                "build": self.info.get("CFBundleVersion"),
                "root": self.root,
                "executable": self.main,
                "minimum_os": self.info.get("MinimumOSVersion"),
                "binary_minimum_os": self.binary_min_os(),
                "macho_files": len(self.binaries),
            },
            "modules": modules,
            "library_patches": patches,
            "extensions": self.extension_rows(),
            "encrypted_binaries": sorted(path for path in self.binaries if self.encrypted(path)),
            "metadata_issues": issues,
            "native_ad_sdk_markers": self.markers(main, self.rules.get("ad_sdk_markers", [])),
            "recommended": {
                "remove": [module["path"] for module in modules if module["recommended"]],
                "patch": [row["path"] for row in patches if row["state"] == "applicable"],
                "fix_metadata": bool(issues),
            },
            "limitations": [
                "Static analysis cannot establish every runtime or server-driven pop-up.",
                "Native ad SDK markers are reported, never patched.",
                "Every cleaned IPA must be signed for the destination device before installation.",
            ],
        }

    def plan(self, modules, extensions):
        """Entries and binaries that go: the selection, then what only they still needed."""
        gone = set()
        for path in modules:
            gone |= self.entries_under(self.container(path))
        for root in extensions:
            gone |= self.entries_under(root)
        orphans = []
        while True:
            removed_binaries = {path for path in self.binaries if path in gone}
            added = False
            for path in sorted(self.binaries):
                if path in gone or path in self.executables():
                    continue
                loaders = {row["binary"] for row in self.loaded_by.get(path, [])}
                if loaders and loaders <= removed_binaries:
                    gone |= self.entries_under(self.container(path))
                    orphans.append(path)
                    added = True
            if not added:
                return gone, removed_binaries, orphans

    def resolved_loads(self, binary):
        parsed = self.parsed.get(binary)
        if isinstance(parsed, Exception) or not parsed:
            return set()
        return {(name, self.resolve(binary, name)) for macho in parsed for name in macho.libraries}


def fixed_info(bundle):
    info = dict(bundle.info)
    applied = []
    for issue in bundle.metadata_issues():
        key = "CFBundleIdentifier" if issue["code"] == "BUNDLE_ID_TRAILING_DOT" else "MinimumOSVersion"
        info[key] = issue["fix"]
        applied.append({"key": key, "from": issue["value"], "to": issue["fix"]})
    fmt = plistlib.FMT_BINARY if bundle.info_raw.startswith(b"bplist") else plistlib.FMT_XML
    return plistlib.dumps(info, fmt=fmt, sort_keys=False), applied


def analyze(source, rules):
    source = Path(source)
    with tempfile.TemporaryDirectory(prefix="ipa-analyze-") as temporary:
        current, rar = source, is_rar(source)
        if rar:
            current = Path(temporary) / "converted.ipa"
            convert_rar(source, current)
        with zipfile.ZipFile(current) as archive:
            report = Bundle(archive, rules).report()
    report["archive"] = {"format": "rar" if rar else "zip", "bytes": source.stat().st_size}
    return report


def clean(source, destination, rules, remove=(), remove_extensions=(), opt_in=(), recommended=False, fix_metadata=False, keep_metadata=False):
    source, destination = Path(source), Path(destination)
    if source.resolve() == destination.resolve():
        raise Refused("Write the cleaned copy to a new path; the source is kept")
    source_sha = digest(source)
    with tempfile.TemporaryDirectory(prefix="ipa-clean-") as temporary:
        current, converted = source, is_rar(source)
        if converted:
            current = Path(temporary) / "converted.ipa"
            convert_rar(source, current)
        with zipfile.ZipFile(current) as archive:
            bundle = Bundle(archive, rules)
            before = bundle.report()
            modules = {module["path"]: module for module in before["modules"]}
            selected = set(remove)
            if recommended:
                selected |= set(before["recommended"]["remove"])
            for rule_id in opt_in:
                selected |= {path for path, module in modules.items() if module["rule"] == rule_id}
            for path in sorted(selected):
                if path not in modules:
                    raise Refused("Not an injected module: " + path)
                if not modules[path]["removable"]:
                    raise Refused(f"{path}: {modules[path]['blocked_reason']}")
            for root in remove_extensions:
                if root not in bundle.extensions:
                    raise Refused("Not an app extension: " + root)
            # Tweaks call a hook runtime through dlsym: it may only go together with all of them.
            if any(modules[path]["category"] == "hook-runtime" for path in selected):
                staying = sorted(path for path, module in modules.items() if module["hook_markers"] and module["category"] != "hook-runtime" and path not in selected)
                if staying:
                    raise Refused("The hook runtime is still needed by " + ", ".join(PurePosixPath(path).name for path in staying))

            gone, removed_binaries, orphans = bundle.plan(selected, set(remove_extensions))
            replacements, removed_loads = {}, {}
            for path, data in bundle.binaries.items():
                if path in gone:
                    continue
                updated, loads = remove_loads(data, lambda name, path=path: bundle.resolve(path, name) in removed_binaries)
                if loads:
                    replacements[path] = updated
                    removed_loads[path] = loads
            patched = {}
            for path in (before["recommended"]["patch"] if recommended else []):
                updated, info = patch_library(path, replacements.get(path, bundle.binaries[path]), rules)
                if info:
                    replacements[path] = updated
                    patched[path] = info
            metadata = []
            if fix_metadata or (recommended and not keep_metadata and before["recommended"]["fix_metadata"]):
                data, metadata = fixed_info(bundle)
                if metadata:
                    replacements[bundle.info_path] = data
            # Anything a remaining binary loaded must still be there, unless the load went too.
            expected = {}
            for path in bundle.binaries:
                if path in gone:
                    continue
                loads = bundle.resolved_loads(path)
                for name, target in loads:
                    if target in gone and name not in removed_loads.get(path, []):
                        raise Refused(f"{path} would lose {target}")
                expected[path] = {(name, target) for name, target in loads if target is not None and name not in removed_loads.get(path, [])}

        if not gone and not replacements:
            shutil.copyfile(current, destination)
        else:
            rewrite_zip(current, destination, gone, replacements)
        verify(current, destination, gone, replacements, before, rules, metadata, expected)

    return {
        "tool_version": TOOL_VERSION,
        "rules_version": rules.get("version"),
        "source_sha256": source_sha,
        "output_sha256": digest(destination),
        "source_bytes": source.stat().st_size,
        "output_bytes": destination.stat().st_size,
        "changed": bool(gone or replacements),
        "rar_converted_to_zip": converted,
        "app": before["app"],
        "removed_modules": [modules[path] | {"orphaned_dependency": False} for path in sorted(selected)]
        + [{"path": path, "orphaned_dependency": True} for path in orphans],
        "removed_extensions": sorted(remove_extensions),
        "removed_entries": len(gone),
        "removed_loads": removed_loads,
        "patched_libraries": patched,
        "metadata_fixes": metadata,
        "verification": {"crc": True, "retained_entries_unchanged": True, "import_ordinals_preserved": True, "no_dangling_loads": True},
        "requires_resigning": True,
        "runtime_tested": False,
        "native_ad_sdk_markers": before["native_ad_sdk_markers"],
    }


def verify(source, destination, gone, replacements, before, rules, metadata, expected):
    """Reads the written copy back: CRCs, identity, every kept load still lands, retained entries untouched."""
    with zipfile.ZipFile(destination) as archive:
        failed = archive.testzip()
        if failed:
            raise Refused("ZIP CRC failed: " + failed)
        if gone & set(archive.namelist()):
            raise Refused("A removed entry survived")
        after = Bundle(archive, rules)
        expected_id = next((fix["to"] for fix in metadata if fix["key"] == "CFBundleIdentifier"), before["app"]["bundle_id"])
        if (after.info.get("CFBundleIdentifier"), after.info.get("CFBundleShortVersionString")) != (expected_id, before["app"]["version"]):
            raise Refused("App identity changed")
        if set(after.binaries) != set(expected):
            raise Refused("The set of binaries changed unexpectedly")
        for path, loads in expected.items():
            for name, target in loads:
                if after.resolve(path, name) != target:
                    raise Refused(f"{path} has a dangling load {name}")
    with zipfile.ZipFile(source) as old, zipfile.ZipFile(destination) as new:
        for entry in old.infolist():
            if entry.filename in gone or entry.filename in replacements:
                continue
            kept = new.getinfo(entry.filename)
            if (kept.CRC, kept.file_size, kept.external_attr) != (entry.CRC, entry.file_size, entry.external_attr):
                raise Refused("Retained entry changed: " + entry.filename)


# ---------------------------------------------------------------- CLI

def batch(directory, report_path, rules, apply=False, only=(), opt_in=()):
    directory = Path(directory).resolve()
    files = sorted(directory.glob("*.ipa"))
    if only:
        missing = set(only) - {file.name for file in files}
        if missing:
            raise Refused("Selected IPA not found: " + ", ".join(sorted(missing)))
        files = [file for file in files if file.name in set(only)]
    if not files:
        raise Refused("No IPA files found")
    private = directory.parent / ".local"
    private.mkdir(mode=0o700, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix="ipa-cleanup-stage-", dir=str(private)))
    reports = []
    try:
        for source in files:
            report = clean(source, stage / source.name, rules, recommended=True, opt_in=opt_in)
            report["filename"] = source.name
            reports.append(report)
            print(json.dumps({"file": source.name, "removed": len(report["removed_modules"]),
                              "patched_libraries": len(report["patched_libraries"]), "changed": report["changed"]}, ensure_ascii=False), flush=True)
        backup = None
        if apply:
            backup = private / ("ipa-originals-" + time.strftime("%Y%m%d-%H%M%S"))
            backup.mkdir(mode=0o700)
            for source, report in zip(files, reports):
                if digest(source) != report["source_sha256"]:
                    raise Refused("Source changed during cleanup: " + source.name)
                os.link(source, backup / source.name)
            replaced = []
            try:
                for source, report in zip(files, reports):
                    if not report["changed"]:
                        continue
                    os.chmod(stage / source.name, source.stat().st_mode & 0o777)
                    os.replace(stage / source.name, source)
                    replaced.append(source)
            except BaseException:
                for source in replaced:
                    restored = stage / (source.name + ".restore")
                    os.link(backup / source.name, restored)
                    os.replace(restored, source)
                raise
        Path(report_path).parent.mkdir(parents=True, exist_ok=True)
        Path(report_path).write_text(json.dumps({"applied": apply, "backup_directory": str(backup) if backup else None, "apps": reports},
                                                ensure_ascii=False, indent=2) + "\n")
    finally:
        shutil.rmtree(stage)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--rules", type=Path, default=DEFAULT_RULES)
    commands = parser.add_subparsers(dest="command", required=True)
    one = commands.add_parser("analyze")
    one.add_argument("ipa", type=Path)
    two = commands.add_parser("clean")
    two.add_argument("ipa", type=Path)
    two.add_argument("output", type=Path)
    two.add_argument("--recommended", action="store_true")
    two.add_argument("--remove", action="append", default=[], help="Archive path of an injected module")
    two.add_argument("--remove-extension", action="append", default=[], help="Archive path of an app extension (…/PlugIns/X.appex/)")
    two.add_argument("--opt-in", action="append", default=[], help="Also remove modules of this rule id")
    two.add_argument("--fix-metadata", action="store_true")
    two.add_argument("--keep-metadata", action="store_true", help="With --recommended: leave Info.plist as it is")
    two.add_argument("--report", type=Path)
    three = commands.add_parser("batch")
    three.add_argument("directory", type=Path)
    three.add_argument("--report", type=Path, required=True)
    three.add_argument("--apply", action="store_true")
    three.add_argument("--only", action="append", default=[])
    three.add_argument("--opt-in", action="append", default=[])
    args = parser.parse_args(argv)

    try:
        rules = load_rules(args.rules)
        if args.command == "analyze":
            result = analyze(args.ipa, rules)
        elif args.command == "clean":
            result = clean(args.ipa, args.output, rules, args.remove, args.remove_extension, args.opt_in, args.recommended, args.fix_metadata, args.keep_metadata)
            if args.report:
                args.report.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
        else:
            batch(args.directory, args.report, rules, args.apply, args.only, args.opt_in)
            return 0
    except (Refused, zipfile.BadZipFile) as error:
        print(json.dumps({"error": {"message": str(error)}}, ensure_ascii=False))
        return 2
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
