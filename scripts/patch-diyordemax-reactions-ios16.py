#!/usr/bin/env python3
"""Enable diyordeMax's message long-press REACTION RAIL on iOS 16+ (it was gated to iOS 27+ only).

diyordeMax (shipped as MAXFeatures.dylib) built its reaction rail exclusively for iOS 27: every
rail entry point (_max_reaction_rail_will_display / _install / _will_end, the preview + header-menu
hooks) begins with `cmp <major>,#0x1b ; b.lt <skip>` (0x1b = 27), so on iOS < 27 the whole
rail-reveal path is skipped — no reaction bar appears, and the leftover context-menu hooks leave the
native long-press menu frozen (device-observed on iOS 26.6; works on iOS 27).

We lower that version threshold from 27 (0x1b) to 16 (0x10) at each gate, so iOS 16+ takes the same
path iOS 27 did and the rail shows. MAX's own minimum is iOS 16, so this covers every supported
device. Only the `major` operand of these six `cmp xN,#0x1b` sites is changed (imm12 27 -> 16);
nothing else moves.

Sites are the six reaction/context-menu version gates; each is verified by its exact `cmp xN,#0x1b`
word before patching, so the script refuses to touch a binary whose layout changed (relink) rather
than corrupt it. Usage: patch-diyordemax-reactions-ios16.py <in.dylib> <out.dylib>
"""
import struct, sys

# file offset -> expected `cmp wN/xN,#0x1b` instruction word (LE hex). The COMPLETE set of iOS-version
# gates across diyorde's reaction/context-menu subsystem (found by scanning every cmp #0x1b and keeping
# only the reaction/menu functions). All must flip together, or the rail shows but a still-gated helper
# (_Attach…Window, _Hook…, block_invokes) tears it down after ~1s on iOS < 27.
SITES = {
    0x08eb4: "1f6f00f1",  # contextMenuInteraction:willDisplayMenuForConfiguration:animator:
    0x098c0: "7f6e00f1",  # contextMenuInteraction:willEndForConfiguration:animator:
    0x09c7c: "ff6e00f1",  # previewFromMaxForConfiguration:identifier:useHighlight:
    0x0a788: "ff6e00f1",  # _max_native_menu_should_replace
    0x0b004: "9f6e00f1",  # _max_native_menu_install_on_view
    0x0b2d8: "9f6e00f1",  # _max_native_menu_install_on_view (2nd)
    0x0eb78: "bf6e00f1",  # _max_reaction_rail_prepare
    0x13318: "bf6e00f1",  # _max_reaction_rail_restore_double_tap
    0x13914: "9f6e00f1",  # _max_reaction_rail_install
    0x13fec: "9f6e00f1",  # _max_reaction_rail_install (2nd)
    0x14234: "7f6e0071",  # ___max_reaction_rail_install_block_invoke  (32-bit cmp w19)
    0x14d74: "3f6f00f1",  # _max_reaction_rail_will_display (backdrop gate)
    0x14e80: "7f6e00f1",  # _max_reaction_rail_will_display (rail-reveal gate)
    0x15600: "bf6e00f1",  # ___max_reaction_rail_will_display_block_invoke
    0x15708: "bf6e00f1",  # ___max_reaction_rail_will_display_block_invoke (2nd)
    0x15d24: "bf6e00f1",  # _AttachIOS27RailWindow
    0x16804: "3f6f00f1",  # _max_reaction_rail_will_end
    0x1bd28: "1f6f00f1",  # _HookHandlerSelectReaction
    0x1c004: "df6e00f1",  # _HookRSVCHeightChange
    0x1c70c: "1f6f00f1",  # _HookCellWillEnd
    0x1c7d4: "ff6e00f1",  # _HookDoubleTapSetState
}
OLD_IMM, NEW_IMM = 0x1b, 0x10  # iOS 27 -> iOS 16

def reimm(word_le_hex, new_imm):
    w = struct.unpack("<I", bytes.fromhex(word_le_hex))[0]
    assert ((w >> 10) & 0xFFF) == OLD_IMM, "not a cmp #0x1b"
    w = (w & ~(0xFFF << 10)) | ((new_imm & 0xFFF) << 10)
    return struct.pack("<I", w)

def main(src, dst):
    d = bytearray(open(src, "rb").read())
    patched = 0
    for off, exp in SITES.items():
        got = d[off:off+4].hex()
        if got != exp:
            raise SystemExit(f"site 0x{off:x}: expected {exp}, found {got} — binary layout changed; aborting.")
        d[off:off+4] = reimm(exp, NEW_IMM)
        patched += 1
    if patched != len(SITES):
        raise SystemExit(f"patched {patched}/{len(SITES)}")
    open(dst, "wb").write(d)
    print(f"patched {patched} reaction version-gates 27->16 -> {dst}")

if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    main(sys.argv[1], sys.argv[2])
