#!/usr/bin/env python3
"""Delta v3 prototype: compose one team's signed IPA from a team-neutral core plus a small team patch.

    proto.py ORIGINAL.ipa REFERENCE.ipa TARGET.ipa [OUT.ipa]

REFERENCE and TARGET are the same artifact signed for two different Apple teams. The core is cut from
REFERENCE once per app version: for every Mach-O that signing changed, the bytes outside its slice headers
and code-signature regions, deflated as independent non-final blocks ending in a full flush. TARGET's patch
is only what differs per team: those headers and signature regions, plus whole small files (profile,
Info.plist, CodeResources, a per-team dylib). The composed IPA is written as ordinary DEFLATE entries:
[header segment][shared core segment][signature segment, final block], so its size is close to a normal
IPA and no entry is stored uncompressed.

The prototype checks that every composed entry has exactly the bytes TARGET has (sha256), that the ZIP
reads back cleanly, and reports core size, patch size and composed size. Nothing here is used in production.
"""
import hashlib
import json
import struct
import sys
import zipfile
import zlib

PAGE = 4096
MH_MAGIC_64 = b"\xcf\xfa\xed\xfe"
FAT_MAGIC = b"\xca\xfe\xba\xbe"
LC_CODE_SIGNATURE = 0x1D


def slices(data: bytes):
    if data[:4] == FAT_MAGIC:
        count = struct.unpack(">I", data[4:8])[0]
        return [struct.unpack(">II", data[8 + 20 * k + 8:8 + 20 * k + 16]) for k in range(count)]
    if data[:4] == MH_MAGIC_64:
        return [(0, len(data))]
    return None


def signature_range(data: bytes, base: int):
    if data[base:base + 4] != MH_MAGIC_64:
        return None
    ncmds = struct.unpack("<I", data[base + 16:base + 20])[0]
    offset = base + 32
    for _ in range(ncmds):
        cmd, size = struct.unpack("<II", data[offset:offset + 8])
        if cmd == LC_CODE_SIGNATURE:
            dataoff, datasize = struct.unpack("<II", data[offset + 8:offset + 16])
            return base + dataoff, base + dataoff + datasize
        offset += size
    return None


def variable_ranges(data: bytes):
    """Per-team byte ranges of a signed Mach-O: each slice's first page and its signature, sorted, merged."""
    ranges = []
    for base, _size in slices(data) or []:
        ranges.append((base, min(base + PAGE, len(data))))
        signature = signature_range(data, base)
        if signature is None:
            return None
        ranges.append(signature)
    ranges.sort()
    merged = []
    for start, end in ranges:
        if merged and start <= merged[-1][1]:
            merged[-1] = (merged[-1][0], max(merged[-1][1], end))
        else:
            merged.append((start, end))
    return merged


def segments(length: int, variable):
    """Alternating (kind, start, end) covering [0, length): kind 'team' for variable ranges, 'core' between them."""
    out, cursor = [], 0
    for start, end in variable:
        if start > cursor:
            out.append(("core", cursor, start))
        out.append(("team", start, end))
        cursor = end
    if cursor < length:
        out.append(("core", cursor, length))
    return out


def deflate_part(data: bytes, final: bool) -> bytes:
    """Raw DEFLATE blocks from a fresh compressor: self-contained, byte-aligned; final only for the last part."""
    compressor = zlib.compressobj(6, zlib.DEFLATED, -15)
    return compressor.compress(data) + compressor.flush(zlib.Z_FINISH if final else zlib.Z_FULL_FLUSH)


def raw_entry(archive: zipfile.ZipFile, path: str, info: zipfile.ZipInfo) -> bytes:
    with open(path, "rb") as handle:
        handle.seek(info.header_offset)
        header = handle.read(30)
        name_length, extra_length = struct.unpack("<HH", header[26:30])
        handle.seek(info.header_offset + 30 + name_length + extra_length)
        return handle.read(info.compress_size)


def main() -> int:
    original_path, reference_path, target_path = sys.argv[1:4]
    out_path = sys.argv[4] if len(sys.argv) > 4 else None
    original, reference, target = (zipfile.ZipFile(p) for p in (original_path, reference_path, target_path))
    original_by_name = {i.filename: i for i in original.infolist()}
    reference_by_name = {i.filename: i for i in reference.infolist()}

    entries = []  # (name, method, crc, usize, payload bytes, date_time)
    core_bytes = patch_bytes = whole_files = macho_cored = macho_whole = 0
    for info in target.infolist():
        name = info.filename
        if info.is_dir():
            entries.append((name, 0, 0, 0, b"", info.date_time))
            continue
        data = target.read(name)
        source = original_by_name.get(name)
        if source is not None and source.file_size == len(data) and source.CRC == zlib.crc32(data) and original.read(name) == data:
            entries.append((name, source.compress_type, source.CRC, len(data), raw_entry(original, original_path, source), info.date_time))
            continue

        ref_info = reference_by_name.get(name)
        ref = reference.read(name) if ref_info is not None else None
        variable = variable_ranges(ref) if ref is not None and slices(ref) else None
        cored = False
        if variable is not None and len(ref) == len(data) and variable_ranges(data) == variable:
            parts = segments(len(data), variable)
            if all(ref[s:e] == data[s:e] for kind, s, e in parts if kind == "core"):
                payload = b""
                for index, (kind, s, e) in enumerate(parts):
                    piece = deflate_part((ref if kind == "core" else data)[s:e], final=index == len(parts) - 1)
                    payload += piece
                    if kind == "core":
                        core_bytes += len(piece)
                    else:
                        patch_bytes += len(piece)
                entries.append((name, zipfile.ZIP_DEFLATED, zlib.crc32(data), len(data), payload, info.date_time))
                cored = True
                macho_cored += 1
        if not cored:
            payload = deflate_part(data, final=True)
            patch_bytes += len(payload)
            whole_files += 1
            macho_whole += 1 if slices(data) else 0
            entries.append((name, zipfile.ZIP_DEFLATED, zlib.crc32(data), len(data), payload, info.date_time))

    out = out_path or "/tmp/delta-v3-composed.ipa"
    central, offset = [], 0
    with open(out, "wb") as handle:
        for name, method, crc, usize, payload, date_time in entries:
            encoded = name.encode("utf-8")
            flags = 0x800 if any(ord(c) > 127 for c in name) else 0
            dos_time = (date_time[3] << 11) | (date_time[4] << 5) | (date_time[5] // 2)
            dos_date = ((date_time[0] - 1980) << 9) | (date_time[1] << 5) | date_time[2]
            handle.write(struct.pack("<IHHHHHIIIHH", 0x04034B50, 20, flags, method, dos_time, dos_date, crc, len(payload), usize, len(encoded), 0))
            handle.write(encoded)
            handle.write(payload)
            central.append(struct.pack("<IHHHHHHIIIHHHHHII", 0x02014B50, 0x0314, 20, flags, method, dos_time, dos_date, crc, len(payload), usize,
                                       len(encoded), 0, 0, 0, 0, (0o40755 if name.endswith("/") else 0o100644) << 16, offset) + encoded)
            offset += 30 + len(encoded) + len(payload)
        directory = b"".join(central)
        handle.write(directory)
        handle.write(struct.pack("<IHHHHIIH", 0x06054B50, 0, 0, len(entries), len(entries), len(directory), offset, 0))

    composed = zipfile.ZipFile(out)
    bad = composed.testzip()
    mismatched = [i.filename for i in target.infolist() if not i.is_dir()
                  and hashlib.sha256(composed.read(i.filename)).digest() != hashlib.sha256(target.read(i.filename)).digest()]
    import os
    print(json.dumps({
        "entries": len(entries),
        "zip_ok": bad is None,
        "entries_identical_to_target": not mismatched,
        "mismatched": mismatched[:5],
        "macho_from_core": macho_cored,
        "macho_whole_in_patch": macho_whole,
        "whole_files_in_patch": whole_files,
        "core_bytes": core_bytes,
        "patch_bytes": patch_bytes,
        "composed_bytes": os.path.getsize(out),
        "target_bytes": os.path.getsize(target_path),
    }))
    if not out_path:
        os.unlink(out)
    return 0 if bad is None and not mismatched else 1


if __name__ == "__main__":
    sys.exit(main())
