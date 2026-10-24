#!/usr/bin/env python3
# Resolve a vmaddr in VKClient to its string (handles __cstring and __cfstring via chained fixups).
import struct, sys
data = open(sys.argv[1], "rb").read()
ncmds, = struct.unpack_from("<I", data, 16)
segs = []; sects = {}
off = 32
for _ in range(ncmds):
    cmd, cmdsize = struct.unpack_from("<II", data, off)
    if cmd == 0x19:
        sn = data[off+8:off+24].split(b"\0")[0].decode()
        vmaddr, vmsize, fileoff, filesize = struct.unpack_from("<QQQQ", data, off+24)
        nsects, = struct.unpack_from("<I", data, off+64)
        segs.append((vmaddr, vmsize, fileoff, filesize, sn))
        so = off+72
        for _s in range(nsects):
            n = data[so:so+16].split(b"\0")[0].decode()
            sa, ss = struct.unpack_from("<QQ", data, so+32); soo, = struct.unpack_from("<I", data, so+48)
            sects[(sn, n)] = (sa, ss, soo); so += 80
    off += cmdsize
IMAGE_BASE = 0x100000000
def v2o(v):
    for vmaddr0, vmsize, fileoff, filesize, name in segs:
        if vmaddr0 <= v < vmaddr0+vmsize and v-vmaddr0 < filesize:
            return fileoff + (v - vmaddr0)
    return None
def decode_ptr(v):
    o = v2o(v)
    if o is None: return None
    raw, = struct.unpack_from("<Q", data, o)
    if raw == 0: return None
    if (raw>>63)&1: return None
    return IMAGE_BASE + (((raw>>36)&0xFF)<<56 | (raw & 0xFFFFFFFFF))
def cstr(v):
    o = v2o(v)
    if o is None: return None
    e = data.find(b"\0", o)
    try: return data[o:e].decode("utf-8")
    except: return repr(data[o:e])
def sect_of(v):
    for k,(sa,ss,so) in sects.items():
        if sa <= v < sa+ss: return k
    return None
def resolve(v):
    k = sect_of(v)
    if k is None: return (None, "unmapped")
    if k[1] == "__cstring" or k[1] == "__objc_methname":
        return (cstr(v), k[1])
    if k[1] == "__cfstring":
        # struct: isa(0) flags(8) data(16) len(24)
        dptr = decode_ptr(v+16)
        return (cstr(dptr) if dptr else None, "__cfstring")
    if k[1] in ("__objc_selrefs","__objc_classrefs","__objc_superrefs"):
        t = decode_ptr(v)
        return (cstr(t) if t else None, k[1])
    # maybe a pointer slot to a string
    t = decode_ptr(v)
    if t is not None:
        kk = sect_of(t)
        if kk and kk[1] in ("__cstring","__objc_methname"):
            return (cstr(t), "ptr->"+kk[1])
    return (None, k[1])
for a in sys.argv[2:]:
    v = int(a, 16)
    s, src = resolve(v)
    print(f"0x{v:x}  [{src}]  {s!r}")
