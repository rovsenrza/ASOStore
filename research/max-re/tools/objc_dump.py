#!/usr/bin/env python3
# Minimal Mach-O (arm64, single-arch) ObjC class dumper that understands
# DYLD_CHAINED_PTR_64 rebases and relative method lists. Outputs class -> (selector @ imp).
import struct, sys

path = sys.argv[1]
data = open(path, "rb").read()

MH_MAGIC_64 = 0xFEEDFACF
LC_SEGMENT_64 = 0x19

magic, = struct.unpack_from("<I", data, 0)
assert magic == MH_MAGIC_64, hex(magic)
ncmds, = struct.unpack_from("<I", data, 16)
# header is 32 bytes
segs = []   # (vmaddr, vmsize, fileoff, filesize, name)
sects = {}  # name -> (addr, size, offset)
off = 32
for _ in range(ncmds):
    cmd, cmdsize = struct.unpack_from("<II", data, off)
    if cmd == LC_SEGMENT_64:
        segname = data[off+8:off+24].split(b"\0")[0].decode()
        vmaddr, vmsize, fileoff, filesize = struct.unpack_from("<QQQQ", data, off+24)
        nsects, = struct.unpack_from("<I", data, off+64)
        segs.append((vmaddr, vmsize, fileoff, filesize, segname))
        so = off + 72
        for _s in range(nsects):
            sn = data[so:so+16].split(b"\0")[0].decode()
            saddr, ssize = struct.unpack_from("<QQ", data, so+32)
            soff, = struct.unpack_from("<I", data, so+48)
            sects[(segname, sn)] = (saddr, ssize, soff)
            so += 80
    off += cmdsize

def v2o(vmaddr):
    for vmaddr0, vmsize, fileoff, filesize, name in segs:
        if vmaddr0 <= vmaddr < vmaddr0 + vmsize:
            d = vmaddr - vmaddr0
            if d < filesize:
                return fileoff + d
    return None

def rd(vmaddr, n):
    o = v2o(vmaddr)
    if o is None: return None
    return data[o:o+n]

IMAGE_BASE = 0x100000000   # __TEXT vmaddr; ptr_format 6 = DYLD_CHAINED_PTR_64_OFFSET

def decode_ptr(vmaddr):
    """Read an 8-byte chained-fixup slot at vmaddr, return (target_vmaddr or None, is_bind)."""
    b = rd(vmaddr, 8)
    if b is None or len(b) < 8: return (None, False)
    raw, = struct.unpack("<Q", b)
    if raw == 0: return (None, False)
    bind = (raw >> 63) & 1
    if bind:
        return (None, True)
    target = raw & 0xFFFFFFFFF          # 36 bits
    high8 = (raw >> 36) & 0xFF
    return (IMAGE_BASE + ((high8 << 56) | target), False)

def cstr(vmaddr):
    o = v2o(vmaddr)
    if o is None: return None
    end = data.find(b"\0", o)
    return data[o:end].decode("utf-8", "replace")

def read_method_list(ml_vmaddr):
    out = []
    hdr = rd(ml_vmaddr, 8)
    if not hdr or len(hdr) < 8: return out
    entsize, count = struct.unpack("<II", hdr)
    relative = (entsize & 0x80000000) != 0
    esz = entsize & 0xFFFF
    base = ml_vmaddr + 8
    for i in range(count):
        if relative:
            ent = base + i*12
            b = rd(ent, 12)
            if not b or len(b) < 12: break
            nameRef, types, impRef = struct.unpack("<iii", b)
            # name: self-relative at ent+0 -> selref pointer slot (chained) -> cstring
            name_loc = (ent + 0 + nameRef) & 0xFFFFFFFFFFFFFFFF
            tgt, isb = decode_ptr(name_loc)
            sel = cstr(tgt) if tgt else None
            if sel is None:            # maybe direct selector string
                sel = cstr(name_loc)
            imp = (ent + 8 + impRef) & 0xFFFFFFFFFFFFFFFF
            out.append((sel, imp))
        else:
            ent = base + i*esz
            b = rd(ent, 24)
            if not b or len(b) < 24: break
            np, tp, ip = struct.unpack("<QQQ", b)
            # pointer-based: each is a chained slot at ent+0/+8/+16
            nt,_ = decode_ptr(ent+0); it,_ = decode_ptr(ent+16)
            sel = cstr(nt) if nt else None
            out.append((sel, it))
    return out

def class_ro(cls_vmaddr):
    # class_t: isa(0) super(8) cache(16) vtable(24) data(32)
    data_slot = cls_vmaddr + 32
    tgt, isb = decode_ptr(data_slot)
    if tgt is None: return None
    ro = tgt & ~0x7
    return ro

def ro_name_methods(ro):
    name_slot = ro + 24
    nt,_ = decode_ptr(name_slot)
    name = cstr(nt) if nt else None
    bm_slot = ro + 32
    bm,_ = decode_ptr(bm_slot)
    methods = read_method_list(bm) if bm else []
    return name, methods

def dump_class(cls_vmaddr):
    ro = class_ro(cls_vmaddr)
    if ro is None: return None
    name, inst = ro_name_methods(ro)
    # metaclass (class methods): class_t.isa at offset 0 -> metaclass -> its class_ro baseMethods
    meta_slot_tgt,_ = decode_ptr(cls_vmaddr + 0)
    cls_methods = []
    if meta_slot_tgt:
        mro = class_ro(meta_slot_tgt)
        if mro is not None:
            _, cls_methods = ro_name_methods(mro)
    methods = [("-"+ (s or "?"), imp) for s,imp in inst] + [("+"+(s or "?"), imp) for s,imp in cls_methods]
    return (name, methods)

# iterate __objc_classlist
cl = sects.get(("__DATA_CONST","__objc_classlist")) or sects.get(("__DATA","__objc_classlist"))
if not cl:
    # search any segment
    for k in sects:
        if k[1] == "__objc_classlist": cl = sects[k]; break
assert cl, "no __objc_classlist"
addr, size, foff = cl
nptr = size // 8
results = {}
for i in range(nptr):
    slot = addr + i*8
    tgt, isb = decode_ptr(slot)
    if not tgt: continue
    r = dump_class(tgt)
    if r and r[0]:
        results[r[0]] = r[1]

want = sys.argv[2:] if len(sys.argv) > 2 else None
for name in sorted(results):
    if want and not any(w.lower() in name.lower() for w in want):
        continue
    print(f"@ {name}")
    for sel, imp in results[name]:
        print(f"   0x{imp:x}  {sel}")
print(f"# total classes parsed: {len(results)}", file=sys.stderr)
