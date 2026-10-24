#!/usr/bin/env python3
# Scan arm64 __TEXT for ADRP(+ADD) sequences that compute a target vmaddr, report code addrs that reference it.
import struct, sys
data = open(sys.argv[1], "rb").read()
targets = [int(a,16) for a in sys.argv[2:]]
ncmds,=struct.unpack_from("<I",data,16); segs=[]; off=32; text=None
for _ in range(ncmds):
    cmd,cs=struct.unpack_from("<II",data,off)
    if cmd==0x19:
        sn=data[off+8:off+24].split(b"\0")[0].decode();va,vs,fo,fs=struct.unpack_from("<QQQQ",data,off+24)
        segs.append((va,vs,fo,fs,sn))
        if sn=="__TEXT":
            # find __text section
            ns,=struct.unpack_from("<I",data,off+64);so=off+72
            for _s in range(ns):
                n=data[so:so+16].split(b"\0")[0].decode();sa,ss=struct.unpack_from("<QQ",data,so+32);soo,=struct.unpack_from("<I",data,so+48)
                if n=="__text": text=(sa,ss,soo)
                so+=80
    off+=cs
sa,ss,soff=text
# decode: track adrp results per register across the text section linearly
# ADRP: 1ii1 0000 immhi(19) ..., bits: op=1, immlo=bits29-30, immhi=bits5-23, Rd=bits0-4
def decode_adrp(instr, pc):
    if (instr & 0x9f000000) != 0x90000000: return None
    Rd = instr & 0x1f
    immlo = (instr >> 29) & 0x3
    immhi = (instr >> 5) & 0x7ffff
    imm = (immhi << 2) | immlo
    if imm & (1<<20): imm -= (1<<21)
    base = (pc & ~0xfff) + (imm << 12)
    return (Rd, base)
def decode_add_imm(instr):
    # ADD (immediate) 64-bit: sf=1, 1 0 0 0 1 0 shift(2) imm12 Rn Rd ; op 0x91000000
    if (instr & 0xff800000) != 0x91000000: return None
    Rd = instr & 0x1f; Rn = (instr>>5)&0x1f
    imm = (instr>>10)&0xfff
    sh = (instr>>22)&1
    if sh: imm <<= 12
    return (Rd, Rn, imm)
def decode_ldr_uimm(instr):
    # LDR (immediate, unsigned offset) 64-bit: 1111 1001 01 imm12 Rn Rt => 0xf9400000
    if (instr & 0xffc00000) != 0xf9400000: return None
    Rt=instr&0x1f; Rn=(instr>>5)&0x1f; imm=((instr>>10)&0xfff)*8
    return (Rt,Rn,imm)
regs={}
n = ss//4
hits={t:[] for t in targets}
for i in range(n):
    pc = sa + i*4
    instr,=struct.unpack_from("<I", data, soff+i*4)
    a=decode_adrp(instr,pc)
    if a:
        regs[a[0]]=a[1]; continue
    ad=decode_add_imm(instr)
    if ad and regs.get(ad[1]) is not None:
        eff=regs[ad[1]]+ad[2]
        if eff in hits: hits[eff].append(pc)
        regs[ad[0]]=None
        continue
    ld=decode_ldr_uimm(instr)
    if ld and ld[1] in regs and regs[ld[1]] is not None:
        eff=regs[ld[1]]+ld[2]
        if eff in hits: hits[eff].append(pc)
    # clobber Rd of this instr if it writes a reg we track (best-effort: clear on any other op to same reg)
for t in targets:
    print(f"target 0x{t:x}: {[hex(x) for x in hits[t][:12]]}")
