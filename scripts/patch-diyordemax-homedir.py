#!/usr/bin/env python3
"""Disable diyordeMax's SideloadHome file-path redirects so the app uses its real (sandbox) paths.

diyordeMax (shipped as MAXFeatures.dylib) isolates the app's filesystem to /var/mobile/DiyordeHomes via
hooks on NSHomeDirectory AND NSSearchPathForDirectoriesInDomains (and related container/group path
rewriters). That is a TrollStore/jailbreak path OUTSIDE a normal app sandbox, so on our standard ad-hoc
signing every write there is denied. This silently breaks the one flow that must write to succeed: a
chat photo's upload-staging file (…/upload_3/<uuid>-uploadimage) and SDImageCache/preview_cache, both of
which resolve their directory through NSSearchPath(Caches) -> redirected -> denied. The photo is never
staged, so no upload request is made and the message fails (red !). Video (NSTemporaryDirectory) and
story photos are unaffected. The account lives in the keychain (SideloadKeychainMode=service), whose
hooks (hook_sec_*) are gated SEPARATELY, so disabling the path redirects keeps the session intact.

Every path-redirect hook gates on diyorde's isolate-active flag with the idiom
    add x8,x8,#0xc68 ; ldarb w8,[x8] ; cmp w8,#1 ; b.ne <passthrough returns the real path>
We turn each such `b.ne` into an unconditional `b`, so every path hook always returns the real path.
Keychain hooks don't use this flag, so they're untouched. Gates are located by the exact instruction
idiom, not fixed addresses. Usage: patch-diyordemax-homedir.py <in.dylib> <out.dylib>
"""
import struct, sys

ADD_X8_C68 = 0x9131a108   # add x8, x8, #0xc68
CMP_W8_1   = 0x7100051f   # cmp w8, #1

def main(src, dst):
    d = bytearray(open(src, 'rb').read())
    w = lambda o: struct.unpack('<I', d[o:o+4])[0]
    patched = []
    for o in range(0, len(d) - 16, 4):
        # add x8,x8,#0xc68 ; ldarb w8,[x8] ; cmp w8,#1 ; b.ne <target>
        if w(o) == ADD_X8_C68 and w(o + 8) == CMP_W8_1 and (w(o + 12) & 0xff00001f) == 0x54000001:
            bne = w(o + 12)
            imm19 = (bne >> 5) & 0x7ffff                      # signed 19-bit
            if imm19 & 0x40000:
                imm19 -= 0x80000
            uncond = 0x14000000 | (imm19 & 0x03ffffff)        # b, same relative target
            d[o + 12:o + 16] = struct.pack('<I', uncond)
            patched.append(o + 12)
    if len(patched) < 2:
        raise SystemExit(f"expected several path-redirect gates, found {len(patched)} (binary changed)")
    print(f"disabled {len(patched)} path-redirect gate(s): " + ", ".join(hex(p) for p in patched))
    open(dst, 'wb').write(d)

if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    main(sys.argv[1], sys.argv[2])
