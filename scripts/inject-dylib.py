#!/usr/bin/env python3
"""Inject a dylib into an IPA's main app executable (LC_LOAD_WEAK_DYLIB) + copy it into App/Frameworks.
Generic (no extension required). The runner re-signs the added dylib with the bundle.
usage: inject-dylib.py IN.ipa OUT.ipa DYLIB"""
import os, shutil, struct, sys, tempfile, zipfile, plistlib

LC_LOAD_WEAK_DYLIB = 0x80000018
LC_SEGMENT_64 = 0x19
MH_MAGIC_64 = 0xFEEDFACF
FAT_MAGIC = 0xCAFEBABE


def add_weak(data, base, path):
    magic, _c, _s, _t, ncmds, sizeofcmds = struct.unpack_from("<IiiIII", data, base)
    if magic != MH_MAGIC_64:
        raise SystemExit(f"not 64-bit Mach-O at {base:#x}")
    off = base + 32
    first_sect = None
    for _ in range(ncmds):
        cmd, size = struct.unpack_from("<II", data, off)
        if cmd in (LC_LOAD_WEAK_DYLIB, 0xC) and path.encode() in bytes(data[off:off + size]):
            print(f"  already loads {path}"); return
        if cmd == LC_SEGMENT_64:
            nsects = struct.unpack_from("<I", data, off + 64)[0]
            for s in range(nsects):
                so = struct.unpack_from("<I", data, off + 72 + s * 80 + 48)[0]
                if so and (first_sect is None or so < first_sect):
                    first_sect = so
        off += size
    name = path.encode() + b"\0"
    cmdsize = (24 + len(name) + 7) & ~7
    end = base + 32 + sizeofcmds
    if first_sect is not None and 32 + sizeofcmds + cmdsize > first_sect:
        raise SystemExit(f"no room for load command ({first_sect - 32 - sizeofcmds} free)")
    if any(data[end:end + cmdsize]):
        raise SystemExit("load-command padding not empty")
    cmd = struct.pack("<IIIIII", LC_LOAD_WEAK_DYLIB, cmdsize, 24, 2, 0x10000, 0x10000) + name
    data[end:end + cmdsize] = cmd.ljust(cmdsize, b"\0")
    struct.pack_into("<II", data, base + 16, ncmds + 1, sizeofcmds + cmdsize)
    print(f"  added LC_LOAD_WEAK_DYLIB {path}")


def patch(file, path):
    data = bytearray(open(file, "rb").read())
    if struct.unpack_from(">I", data, 0)[0] == FAT_MAGIC:
        for i in range(struct.unpack_from(">I", data, 4)[0]):
            so = struct.unpack_from(">I", data, 8 + i * 20 + 8)[0]
            add_weak(data, so, path)
    else:
        add_weak(data, 0, path)
    open(file, "wb").write(data)


def main():
    if len(sys.argv) != 4:
        raise SystemExit(__doc__)
    src, dst, dylib = sys.argv[1:4]
    name = os.path.basename(dylib)
    work = tempfile.mkdtemp()
    try:
        with zipfile.ZipFile(src) as z:
            z.extractall(work)
        app = os.path.join(work, "Payload", next(d for d in os.listdir(os.path.join(work, "Payload")) if d.endswith(".app")))
        with open(os.path.join(app, "Info.plist"), "rb") as p:
            exe = plistlib.load(p).get("CFBundleExecutable")
        os.makedirs(os.path.join(app, "Frameworks"), exist_ok=True)
        shutil.copy2(dylib, os.path.join(app, "Frameworks", name))
        print(exe)
        patch(os.path.join(app, exe), f"@executable_path/Frameworks/{name}")
        if os.path.exists(dst):
            os.remove(dst)
        with zipfile.ZipFile(dst, "w", zipfile.ZIP_DEFLATED, compresslevel=1) as out:
            for root, _d, files in os.walk(work):
                for f in files:
                    full = os.path.join(root, f)
                    out.write(full, os.path.relpath(full, work))
        print(f"wrote {dst}")
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    main()
