#!/usr/bin/env python3
"""Audit local IPAs and remove identified, unreferenced promotional dylibs.

No bundled code is executed. Mach-O code/data offsets and import ordinals stay
unchanged. Refuse removal if an import could be affected; never guess at native
advertising, authentication or payment code. Modified IPAs require re-signing.
"""
import argparse
import copy
import hashlib
import json
import os
import plistlib
import shutil
import struct
import subprocess
import tempfile
import time
import zipfile
from pathlib import Path, PurePosixPath


LOADS = {0xC, 0x80000018, 0x8000001F, 0x80000023, 0x20}
MAGICS = {
    b"\xce\xfa\xed\xfe": ("<", False), b"\xcf\xfa\xed\xfe": ("<", True),
    b"\xfe\xed\xfa\xce": (">", False), b"\xfe\xed\xfa\xcf": (">", True),
}
FAT = {b"\xca\xfe\xba\xbe": (">", False), b"\xbe\xba\xfe\xca": ("<", False),
       b"\xca\xfe\xba\xbf": (">", True), b"\xbf\xba\xfe\xca": ("<", True)}
AD_MARKERS = ("myTarget", "GADBannerView", "GADInterstitial", "YandexMobileAds",
              "AppLovin", "UnityAds", "IronSource", "FBAudienceNetwork")


def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def valid_path(name):
    path = PurePosixPath(name)
    if path.is_absolute() or ".." in path.parts or "\\" in name or "\x00" in name:
        raise ValueError("Unsafe archive path: " + name)


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
        self.endian, self.is64 = MAGICS[data[:4]]
        self.header = 32 if self.is64 else 28
        self.count, self.command_bytes = self.unpack("II", 16)
        if self.count > 8192 or self.header + self.command_bytes > len(data):
            raise ValueError("Invalid Mach-O load command bounds")
        self.commands = []
        self.libraries = []
        pos = self.header
        for _ in range(self.count):
            cmd, size = self.unpack("II", pos)
            if size < 8 or size % 4 or pos + size > self.header + self.command_bytes:
                raise ValueError("Invalid Mach-O command")
            name = None
            if cmd in LOADS:
                offset, = self.unpack("I", pos + 8)
                if not 24 <= offset < size:
                    raise ValueError("Invalid dylib name offset")
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

    def remove(self, names):
        selected, ordinal = [], 0
        for command in self.commands:
            cmd, _, _, name = command
            if cmd in LOADS:
                ordinal += 1
                if PurePosixPath(name).name in names:
                    selected.append((ordinal, command))
        if not selected:
            return self.data, []
        # These injections were appended beyond all real imports. Rather than
        # rewriting binding tables, require that invariant for every input slice.
        referenced = self.ordinals()
        if any(n >= min(n for n, _ in selected) for n in referenced):
            raise ValueError("Removal could change a referenced library ordinal")
        removed = {at for _, (_, at, _, _) in selected}
        commands = b"".join(self.data[at:at + size] for _, at, size, _ in self.commands if at not in removed)
        result = bytearray(self.data)
        struct.pack_into(self.endian + "II", result, 16, self.count - len(selected), len(commands))
        result[self.header:self.header + self.command_bytes] = commands + bytes(self.command_bytes - len(commands))
        check = MachO(bytes(result))
        if check.ordinals() != referenced or result[self.header + self.command_bytes:] != self.data[self.header + self.command_bytes:]:
            raise ValueError("Mach-O payload or imports changed")
        return bytes(result), [c[3] for _, c in selected]


def patch(data, names):
    result = bytearray(data)
    removed = []
    for offset, size in slices(data):
        updated, loads = MachO(data[offset:offset + size]).remove(names)
        result[offset:offset + size] = updated
        removed.extend(loads)
    return bytes(result), sorted(set(removed))


def classify(name, data, remove_music):
    base = PurePosixPath(name).name
    if base == "libobjcpatch.dylib" and b"https://t.me/" in data and b"RSPill" in data:
        return "Telegram overlay / RSPill / CertGuardPill"
    if base == "novikomactivation.dylib" and b"https://t.me/appbankRU" in data and b"NVKCheckChannelGate" in data:
        return "Mandatory Telegram channel gate"
    if base in ("001_iApps.dylib", "flutersult.dylib", "ZVfixZV.dylib", "NewFix2.dylib") and b"https://ipacrack.com" in data and b"SCLAlertView" in data:
        return "Injected external-site popup"
    if base == "HelpshiftX.dylib" and b"https://t.me/MIHALSANCH" in data and b"alertControllerWithTitle:message:preferredStyle:" in data:
        return "Injected Telegram channel promotion popup"
    if base == "YMNight.dylib" and b"https://t.me/" in data and b"VIPPopup" in data:
        if not remove_music:
            raise ValueError("YMNight requires --remove-music-mod; removal also removes mod features")
        return "Integrated YMNight Telegram/VIP popup; entire optional mod removed"
    return None


# These exact builds were inspected with Apple otool: the Lead Objective-C
# method metadata resolves these entry points in BOTH arm64 and arm64e slices.
# Returning before an arm64e PAC prologue leaves the caller's LR unchanged.
# Full-file hashes guard against applying offsets to a different build, and
# the patched hashes make repeated cleanup idempotent. No app delegate, shared
# UIKit alert method, functional fix, or main app instruction is replaced.
LIBRARY_PATCHES = {
    "Lead.dylib": {
        "original_sha256": "85e83304b32180288c1f6deb9a813668b41747a4ded06926b7c635f6f6982309",
        "patched_sha256": "089d3167eeece8464da5ef3150eca5b470f7a3cb525656bb17ab4aa3adbe6256",
        "reason": "Disable remote announcements, promotion cards and their link handler; retain Lead features",
        "sites": [
            (51976, "ff8301d1", "arm64 announcementCardTapped:"),
            (52756, "fc6fbaa9", "arm64 rebuildAnnouncementCards"),
            (55376, "ff8301d1", "arm64 fetchAnnouncement"),
            (12619340, "7f2303d5", "arm64e announcementCardTapped:"),
            (12620140, "7f2303d5", "arm64e rebuildAnnouncementCards"),
            (12622800, "7f2303d5", "arm64e fetchAnnouncement"),
        ],
    },
    "TelegramNotificationFix.dylib": {
        "original_sha256": "0af51a1897bafde99d0f9b7efd64b17d44781a56adf78923cdc4c88e2106eacf",
        "patched_sha256": "48ae2919c77847719c41422200997b95d40169af780cf36efc75fab82913c8db",
        "reason": "Disable DzMohaipa launch promotion block; retain app-group and notification fixes",
        "sites": [(28148, "ffc301d1", "arm64 launch promotion block")],
    },
}


def patch_library(name, data):
    spec = LIBRARY_PATCHES.get(PurePosixPath(name).name)
    if spec is None:
        return data, None
    current_hash = hashlib.sha256(data).hexdigest()
    if current_hash == spec["patched_sha256"]:
        return data, None
    if current_hash != spec["original_sha256"]:
        raise ValueError("Unsupported library build; manual review required: " + name)
    result = bytearray(data)
    sites = []
    for offset, expected_hex, purpose in spec["sites"]:
        expected = bytes.fromhex(expected_hex)
        if offset < 0 or offset % 4 or len(expected) != 4 or data[offset:offset + 4] != expected:
            raise ValueError("Library instruction precondition failed: " + name)
        result[offset:offset + 4] = bytes.fromhex("c0035fd6")  # ARM64 ret
        sites.append({"offset": offset, "before": expected_hex, "after": "c0035fd6", "purpose": purpose})
    result = bytes(result)
    if hashlib.sha256(result).hexdigest() != spec["patched_sha256"]:
        raise ValueError("Patched library hash mismatch: " + name)
    return result, {"reason": spec["reason"], "source_sha256": current_hash,
                    "output_sha256": spec["patched_sha256"], "sites": sites}


def inventory(archive, remove_music):
    entries = archive.infolist()
    if len({i.filename for i in entries}) != len(entries):
        raise ValueError("Duplicate ZIP entry names")
    app_infos = [i.filename for i in entries if i.filename.startswith("Payload/") and i.filename.count("/") == 2 and i.filename.endswith("/Info.plist")]
    if len(app_infos) != 1:
        raise ValueError("Expected exactly one app root")
    info = plistlib.loads(archive.read(app_infos[0]))
    root = app_infos[0].rsplit("/", 1)[0] + "/"
    binaries, removals = {}, {}
    for entry in entries:
        valid_path(entry.filename)
        if entry.flag_bits & 1:
            raise ValueError("Encrypted ZIP entry")
        if entry.is_dir():
            continue
        with archive.open(entry) as stream:
            magic = stream.read(4)
        if magic in MAGICS or magic in FAT:
            data = archive.read(entry)
            binaries[entry.filename] = data
            if entry.filename.endswith(".dylib"):
                reason = classify(entry.filename, data, remove_music)
                if reason:
                    removals[entry.filename] = reason
    names = {PurePosixPath(n).name for n in removals}
    replacements, changed_loads, patched_files = {}, {}, {}
    for name, data in binaries.items():
        if name in removals:
            continue
        updated, loads = patch(data, names)
        if loads:
            replacements[name] = updated
            changed_loads[name] = loads
        updated, patched = patch_library(name, updated)
        if patched:
            replacements[name] = updated
            patched_files[name] = patched
    for name in removals:
        basename = PurePosixPath(name).name
        if not any(basename == PurePosixPath(load).name for loads in changed_loads.values() for load in loads):
            raise ValueError("Unreferenced promotional file requires manual review: " + name)
    main = binaries[root + info["CFBundleExecutable"]]
    markers = [m for m in AD_MARKERS if m.encode() in main]
    return {"bundle_id": info["CFBundleIdentifier"], "version": info.get("CFBundleShortVersionString"),
            "app_root": root, "macho_files": len(binaries), "removed_files": removals,
            "removed_loads": changed_loads, "patched_files": patched_files, "native_ad_sdk_markers": markers,
            "requires_resigning": bool(removals or patched_files)}, replacements


def rewrite_zip(source, destination, removals, replacements):
    # Copy untouched local ZIP records verbatim; only modified executables are
    # recompressed. This preserves resource bytes, modes and original compression.
    with zipfile.ZipFile(source) as old, source.open("rb") as raw, zipfile.ZipFile(destination, "w", allowZip64=True) as new:
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
                raise ValueError("Invalid ZIP record order")
            while left:
                chunk = raw.read(min(left, 1024 * 1024))
                if not chunk:
                    raise ValueError("Truncated ZIP record")
                new.fp.write(chunk)
                left -= len(chunk)
            new.start_dir = new.fp.tell()
            new.filelist.append(item)
            new.NameToInfo[item.filename] = item
            new._didModify = True
        new.comment = old.comment


def convert_rar(source, destination):
    listed = subprocess.run(["bsdtar", "-tf", str(source)], check=True, capture_output=True, text=True).stdout.splitlines()
    for name in listed:
        valid_path(name)
    verbose = subprocess.run(["bsdtar", "-tvf", str(source)], check=True, capture_output=True, text=True).stdout.splitlines()
    if any(line and line[0] not in ("-", "d") for line in verbose):
        raise ValueError("RAR links or special files require manual review")
    with tempfile.TemporaryDirectory(prefix="ipa-rar-") as temporary:
        subprocess.run(["bsdtar", "-xf", str(source), "-C", temporary], check=True, capture_output=True)
        root = Path(temporary)
        with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
            for path in sorted(root.rglob("*")):
                if path.is_symlink():
                    raise ValueError("Unexpected RAR symlink")
                archive.write(path, path.relative_to(root).as_posix())


def process(source, destination, remove_music):
    original_hash = digest(source)
    converted = False
    with tempfile.TemporaryDirectory(prefix="ipa-convert-") as temporary:
        current = source
        with source.open("rb") as stream:
            is_rar = stream.read(7).startswith(b"Rar!")
        if is_rar:
            current = Path(temporary) / "converted.ipa"
            convert_rar(source, current)
            converted = True
        with zipfile.ZipFile(current) as archive:
            report, replacements = inventory(archive, remove_music)
        if replacements or report["removed_files"]:
            rewrite_zip(current, destination, report["removed_files"], replacements)
        else:
            shutil.copyfile(current, destination)
        with zipfile.ZipFile(destination) as archive:
            failed = archive.testzip()
            if failed:
                raise ValueError("ZIP CRC failed: " + failed)
            after, _ = inventory(archive, remove_music)
            if after["removed_files"] or after["patched_files"]:
                raise ValueError("Promotional library or unpatched promotion survived cleanup")
            if (after["bundle_id"], after["version"]) != (report["bundle_id"], report["version"]):
                raise ValueError("App identity changed")
        # Check every retained entry against the original metadata. Changed main
        # binaries have already passed the stricter Mach-O payload/import check.
        with zipfile.ZipFile(current) as old, zipfile.ZipFile(destination) as new:
            for entry in old.infolist():
                if entry.filename in report["removed_files"] or entry.filename in replacements:
                    continue
                kept = new.getinfo(entry.filename)
                if (kept.CRC, kept.file_size, kept.external_attr) != (entry.CRC, entry.file_size, entry.external_attr):
                    raise ValueError("Retained resource changed: " + entry.filename)
    report.update(filename=source.name, source_sha256=original_hash, output_sha256=digest(destination),
                  original_bytes=source.stat().st_size, cleaned_bytes=destination.stat().st_size,
                  rar_converted_to_zip=converted, crc_verified=True, import_ordinals_preserved=True,
                  runtime_tested=False)
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path)
    parser.add_argument("--apply", action="store_true", help="Atomically replace validated IPAs, retaining originals")
    parser.add_argument("--remove-music-mod", action="store_true")
    parser.add_argument("--only", action="append", help="Process only this exact IPA filename; repeat for multiple files")
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    directory = args.directory.resolve()
    files = sorted(directory.glob("*.ipa"))
    if args.only:
        selected = set(args.only)
        missing = selected - {file.name for file in files}
        if missing:
            parser.error("Selected IPA not found: " + ", ".join(sorted(missing)))
        files = [file for file in files if file.name in selected]
    if not files:
        raise SystemExit("No IPA files found")
    private = directory.parent / ".local"
    private.mkdir(mode=0o700, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix="ipa-cleanup-stage-", dir=str(private)))
    reports = []
    try:
        for source in files:
            report = process(source, stage / source.name, args.remove_music_mod)
            reports.append(report)
            print(json.dumps({"file":source.name, "removed":len(report["removed_files"]), "patched_libraries":len(report["patched_files"]), "rar_converted":report["rar_converted_to_zip"]}, ensure_ascii=False), flush=True)
        backup = None
        if args.apply:
            backup = private / ("ipa-originals-" + time.strftime("%Y%m%d-%H%M%S"))
            backup.mkdir(mode=0o700)
            for source, report in zip(files, reports):
                if digest(source) != report["source_sha256"]:
                    raise ValueError("Source changed during cleanup: " + source.name)
                os.link(source, backup / source.name)
            replaced = []
            try:
                for source, report in zip(files, reports):
                    if report["source_sha256"] == report["output_sha256"]:
                        continue
                    mode = source.stat().st_mode & 0o777
                    os.chmod(stage / source.name, mode)
                    os.replace(stage / source.name, source)
                    replaced.append(source)
            except BaseException:
                for source in replaced:
                    restored = stage / (source.name + ".restore")
                    os.link(backup / source.name, restored)
                    os.replace(restored, source)
                raise
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps({"applied":args.apply, "backup_directory":str(backup) if backup else None,
            "limitations":["Static analysis cannot establish every runtime or server-driven alert.",
                           "Native ad SDK markers are reported, not blindly patched.",
                           "Modified IPAs must be signed for the destination device before installation."],
            "apps":reports}, ensure_ascii=False, indent=2) + "\n")
    finally:
        shutil.rmtree(stage)


if __name__ == "__main__":
    main()
