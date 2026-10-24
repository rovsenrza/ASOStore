#!/usr/bin/env python3
# Disassemble a function with r2 (relocs applied), resolve every adrp+add effective address to a string.
import subprocess, sys, re, importlib.util
BIN = sys.argv[1]; FUNC = sys.argv[2]
spec = importlib.util.spec_from_file_location("resolve", sys.argv[0].rsplit("/",1)[0]+"/resolve.py")
# load resolve.py helpers by exec with the binary arg
import struct
data = open(BIN,"rb").read()
ncmds,=struct.unpack_from("<I",data,16); segs=[];sects={};off=32
for _ in range(ncmds):
    cmd,cs=struct.unpack_from("<II",data,off)
    if cmd==0x19:
        sn=data[off+8:off+24].split(b"\0")[0].decode(); va,vs,fo,fs=struct.unpack_from("<QQQQ",data,off+24)
        ns,=struct.unpack_from("<I",data,off+64); segs.append((va,vs,fo,fs,sn)); so=off+72
        for _s in range(ns):
            n=data[so:so+16].split(b"\0")[0].decode(); sa,ss=struct.unpack_from("<QQ",data,so+32); soo,=struct.unpack_from("<I",data,so+48); sects[(sn,n)]=(sa,ss,soo); so+=80
    off+=cs
IB=0x100000000
def v2o(v):
    for va,vs,fo,fs,n in segs:
        if va<=v<va+vs and v-va<fs: return fo+(v-va)
    return None
def dp(v):
    o=v2o(v)
    if o is None: return None
    raw,=struct.unpack_from("<Q",data,o)
    if raw==0 or (raw>>63)&1: return None
    return IB+(((raw>>36)&0xFF)<<56|(raw&0xFFFFFFFFF))
def cs_(v):
    o=v2o(v)
    if o is None: return None
    e=data.find(b"\0",o)
    try: return data[o:e].decode("utf-8")
    except: return None
def so_(v):
    for k,(sa,ss,so) in sects.items():
        if sa<=v<sa+ss: return k
    return None
def resolve(v):
    k=so_(v)
    if not k: return None
    if k[1] in ("__cstring","__objc_methname"): return cs_(v)
    if k[1]=="__cfstring":
        d=dp(v+16); return cs_(d) if d else None
    if k[1] in ("__objc_selrefs","__objc_classrefs","__objc_superrefs"):
        t=dp(v); return cs_(t) if t else None
    t=dp(v)
    if t is not None:
        kk=so_(t)
        if kk and kk[1] in ("__cstring","__objc_methname"): return cs_(t)
    return None

out = subprocess.run(["r2","-qq","-e","scr.color=0","-e","bin.relocs.apply=true",
    "-c",f"af @ {FUNC}; pdf @ {FUNC}", BIN], capture_output=True, text=True).stdout
lines = out.splitlines()
# track adrp reg->base, resolve on matching add
adrp = {}
rx_adrp = re.compile(r'adrp (x\d+), (?:reloc\.\S+ ; )?(0x[0-9a-f]+)')
rx_add  = re.compile(r'add (x\d+), (x\d+), (0x[0-9a-f]+)')
print(out)
print("\n===== resolved string operands =====")
seen=set()
for ln in lines:
    m=rx_adrp.search(ln)
    if m: adrp[m.group(1)]=int(m.group(2),16); continue
    m=rx_add.search(ln)
    if m and m.group(1)==m.group(2) and m.group(2) in adrp:
        eff=adrp[m.group(2)]+int(m.group(3),16)
        s=resolve(eff)
        if s and eff not in seen:
            seen.add(eff); print(f"  0x{eff:x} -> {s!r}")
