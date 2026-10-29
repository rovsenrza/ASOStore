#!/usr/bin/env python3
"""Add MAXShareBridge.dylib to a MAX IPA so its share extension works without an App Group.

Copies ios/max-share-bridge/MAXShareBridge.dylib into MAX.app/Frameworks/ and adds an
LC_LOAD_WEAK_DYLIB to the app binary (@executable_path/Frameworks/...) and to
PlugIns/share.appex/share (@executable_path/../../Frameworks/...). Weak, so a missing dylib
never stops either from launching. The runner re-signs the added dylib with the bundle.

usage: patch-max-share-bridge.py IN.ipa OUT.ipa [DYLIB]
"""
import os
import shutil
import struct
import plistlib
import sys
import tempfile
import zipfile

LC_LOAD_WEAK_DYLIB = 0x80000018
LC_SEGMENT_64 = 0x19
MH_MAGIC_64 = 0xFEEDFACF
FAT_MAGIC = 0xCAFEBABE
NAME = "MAXShareBridge.dylib"


def add_weak_dylib(data: bytearray, base: int, path: str) -> None:
    magic, _cpu, _sub, _ftype, ncmds, sizeofcmds, _flags, _res = struct.unpack_from("<IiiIIIII", data, base)
    if magic != MH_MAGIC_64:
        raise SystemExit(f"not a 64-bit Mach-O slice at {base:#x}")
    header = 32
    offset = base + header
    first_section = None
    for _ in range(ncmds):
        cmd, size = struct.unpack_from("<II", data, offset)
        if cmd in (LC_LOAD_WEAK_DYLIB, 0xC) and path.encode() in bytes(data[offset:offset + size]):
            print(f"  already loads {path}")
            return
        if cmd == LC_SEGMENT_64:
            nsects = struct.unpack_from("<I", data, offset + 64)[0]
            for s in range(nsects):
                sect = offset + 72 + s * 80
                sect_offset = struct.unpack_from("<I", data, sect + 48)[0]
                if sect_offset and (first_section is None or sect_offset < first_section):
                    first_section = sect_offset
        offset += size
    name = path.encode() + b"\0"
    cmdsize = (24 + len(name) + 7) & ~7
    end = base + header + sizeofcmds
    if first_section is not None and header + sizeofcmds + cmdsize > first_section:
        raise SystemExit(f"no room for the load command ({first_section - header - sizeofcmds} bytes free)")
    if any(data[end:end + cmdsize]):
        raise SystemExit("load-command padding is not empty")
    command = struct.pack("<IIIIII", LC_LOAD_WEAK_DYLIB, cmdsize, 24, 2, 0x10000, 0x10000) + name
    data[end:end + cmdsize] = command.ljust(cmdsize, b"\0")
    struct.pack_into("<II", data, base + 16, ncmds + 1, sizeofcmds + cmdsize)
    print(f"  added LC_LOAD_WEAK_DYLIB {path}")


def patch_binary(file: str, path: str) -> None:
    data = bytearray(open(file, "rb").read())
    if struct.unpack_from(">I", data, 0)[0] == FAT_MAGIC:
        for i in range(struct.unpack_from(">I", data, 4)[0]):
            slice_offset = struct.unpack_from(">I", data, 8 + i * 20 + 8)[0]
            add_weak_dylib(data, slice_offset, path)
    else:
        add_weak_dylib(data, 0, path)
    open(file, "wb").write(data)


def main() -> None:
    if len(sys.argv) < 3:
        raise SystemExit(__doc__)
    source, target = sys.argv[1], sys.argv[2]
    dylib = sys.argv[3] if len(sys.argv) > 3 else os.path.join(os.path.dirname(__file__), "..", "ios", "max-share-bridge", NAME)
    work = tempfile.mkdtemp()
    try:
        with zipfile.ZipFile(source) as archive:
            archive.extractall(work)
        apps = [d for d in os.listdir(os.path.join(work, "Payload")) if d.endswith(".app")]
        app = os.path.join(work, "Payload", apps[0])
        with open(os.path.join(app, "Info.plist"), "rb") as plist:
            executable = plistlib.load(plist).get("CFBundleExecutable", "MAX")
        share = os.path.join(app, "PlugIns", "share.appex", "share")
        if not os.path.isfile(share):
            raise SystemExit("no PlugIns/share.appex/share in this IPA")
        os.makedirs(os.path.join(app, "Frameworks"), exist_ok=True)
        shutil.copy2(dylib, os.path.join(app, "Frameworks", NAME))
        print(executable)
        patch_binary(os.path.join(app, executable), f"@executable_path/Frameworks/{NAME}")
        print("share")
        patch_binary(share, f"@executable_path/../../Frameworks/{NAME}")
        if os.path.exists(target):
            os.remove(target)
        with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as out:
            for root, _dirs, files in os.walk(work):
                for f in files:
                    full = os.path.join(root, f)
                    out.write(full, os.path.relpath(full, work))
        print(f"wrote {target}")
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    main()
