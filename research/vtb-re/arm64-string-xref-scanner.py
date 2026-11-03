import struct, sys
data = open("altai.bin","rb").read()
TEXT_OFF=0x4000; TEXT_SZ=0xa5f724; VMBASE=0x100000000
targets = {0x100b313f0:"deceptium.ru", 0x100b05aa0:"altaiapp/welcomes", 0x100b0f26a:"coverView", 0x100ae6fe9:"welcomes"}
# map Rd(adrp) -> (pc, page) pending for the next few instrs
def decode(off):
    w = struct.unpack_from("<I", data, off)[0]
    return w
hits = {t:[] for t in targets}
# track last ADRP per register in a small window
for off in range(TEXT_OFF, TEXT_OFF+TEXT_SZ, 4):
    w = struct.unpack_from("<I", data, off)[0]
    if (w & 0x9F000000) == 0x90000000:  # ADRP
        Rd = w & 0x1F
        immlo = (w>>29)&3
        immhi = (w>>5)&0x7FFFF
        imm = (immhi<<2)|immlo
        if imm & (1<<20): imm -= (1<<21)
        pc = VMBASE + (off-TEXT_OFF) + 0x4000  # vmaddr of this instr = 0x100000000 + fileoff
        pc = VMBASE + (off - 0) # fileoff==vmoff offset: vmaddr=0x100000000+fileoff
        page = (pc & ~0xFFF) + (imm<<12)
        # look ahead up to 8 instrs for ADD imm with Rn==Rd
        for j in range(1,9):
            o2=off+4*j
            if o2+4>len(data): break
            w2=struct.unpack_from("<I",data,o2)[0]
            if (w2 & 0xFF800000)==0x91000000:  # ADD imm (sh=0), 64-bit
                Rn=(w2>>5)&0x1F; imm12=(w2>>10)&0xFFF
                if Rn==Rd:
                    addr=page+imm12
                    if addr in targets:
                        hits[addr].append(hex(pc))
                    break
            # also LDR (literal) or next ADRP to same reg resets
            if (w2 & 0x9F000000)==0x90000000 and (w2&0x1F)==Rd:
                break
for t,name in targets.items():
    print(f"{name} ({hex(t)}): {len(hits[t])} xref(s): {hits[t][:10]}")
