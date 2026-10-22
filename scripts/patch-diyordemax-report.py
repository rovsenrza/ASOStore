#!/usr/bin/env python3
"""Neutralise diyordeMax's "Report a problem" Settings row + its Telegram crash reporter.

MAX 26.35's injected diyordeMax.dylib (shipped as MAXFeatures.dylib) adds a Settings row
"Report a problem" that opens diyorde's own Telegram support chat (tg://resolve, https://t.me/).
We remove that entry point for our catalog build:

  * _max_crash_report_install  -> immediate `ret`, so the crash reporter never installs.
  * The MaxSettingsRegroup hook writes g_reportRow = 1 (`strb w9,[x8,#0x4c1]` after `mov w9,#1`);
    we change the `mov w9,#1` to `mov w9,#0` so the row is never added.

Offsets are resolved by matching the exact instruction words, not hardcoded addresses, so the
script keeps working if diyorde relinks. Usage: patch-diyordemax-report.py <in.dylib> <out.dylib>
"""
import struct, sys

MOV_W9_1 = 0x52800029   # mov w9, #1   (sets g_reportRow)
MOV_W9_0 = 0x52800009   # mov w9, #0
STRB_4C1 = 0x39130509   # strb w9, [x8, #0x4c1]
RET      = 0xd65f03c0

def main(src, dst):
    d = bytearray(open(src, 'rb').read())
    w = lambda o: struct.unpack('<I', d[o:o+4])[0]

    # 1) report row: find a `mov w9,#1` immediately followed (within 8 bytes) by `strb w9,[x8,#0x4c1]`
    row = 0
    for o in range(0, len(d) - 12, 4):
        if w(o) == MOV_W9_1 and STRB_4C1 in (w(o+4), w(o+8)):
            d[o:o+4] = struct.pack('<I', MOV_W9_0); row += 1
    if row != 1:
        raise SystemExit(f"report-row site count = {row}, expected 1 (binary changed)")

    # 2) crash reporter installer: _max_crash_report_install starts `adrp x8,.. ; ldr x8,[x8,#0xb30] ; cmn x8,#1`
    #    Patch its first instruction (the adrp) to `ret`. Locate by the cmn x8,#1 guard that follows.
    #    adrp x8 ; ldr x8,[x8,#imm] ; cmn x8,#1 ; b.ne .. ; ret   (the .once guard, uniquely ends in ret).
    #    Several *_install.once guards share this shape, so disambiguate by the reporter's own once-token
    #    load `ldr x8,[x8,#0xb30]` (0xf9459908). Best-effort: the Settings row removal above is what
    #    actually hides the Telegram entry point, so a relink that moves the token only warns.
    LDR_ONCE = 0xf9459908
    inst = 0
    for o in range(0, len(d) - 20, 4):
        if (w(o+4) == LDR_ONCE and (w(o) & 0x9f00001f) == 0x90000008
                and w(o+8) == 0xb100051f and (w(o+12) & 0xff00001f) == 0x54000001
                and w(o+16) == RET):
            d[o:o+4] = struct.pack('<I', RET); inst += 1
    if inst != 1:
        print(f"warning: crash_report_install site count = {inst} (expected 1); row removal still applied")

    open(dst, 'wb').write(d)
    print(f"patched: report rows={row}, crash-install sites={inst} -> {dst}")

if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    main(sys.argv[1], sys.argv[2])
