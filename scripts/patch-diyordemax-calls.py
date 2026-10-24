#!/usr/bin/env python3
"""
Patch diyorde's diyordeMax.dylib so it stops hijacking MAX incoming calls, while keeping all its
UI features (stories / channel-post comments / reactions / video stickers / live).

WHY: we inject diyordeMax (as the MAXFeatures.dylib slot) for its UI features, but deliver calls +
notifications through OUR server-side relay (re-prefixed bundle + our APNs/VoIP certs). diyordeMax's
`_max_call_notify_install` hooks the call path for diyorde's OWN native-push model:
  - hooks CXCallUpdate.localizedCallerName / setLocalizedCallerName:  -> shows "unknown" on our payload
  - hooks OKMPNWClient._handleIncomingPacket:                         -> intercepts call packets
  - installs a DiyCallObserver and forces reportFromNotifCallStart=YES, callKitRestricted=NO, etc.
With our relay VoIP push, that hook reports a call CallKit cannot establish -> 1s "unknown" popup ->
CXCallEndedReason 1 (Failed) -> falls back to a voice message. Neutralising the whole install function
(overwrite its first instruction with `ret`) restores MAX's NATIVE call handling, which accepts our
relay's VoIP payload exactly as it did on 26.17. The function is call-only; UI features live elsewhere.

Found with REA/Ghidra: _max_call_notify_install @ VA 0x2e634, file offset 190004, first 4 bytes
f4 4f be a9 (stp ...). We overwrite with c0 03 5f d6 (arm64 `ret`, i.e. 0xd65f03c0 LE).

Usage: patch-diyordemax-calls.py <in diyordeMax.dylib> <out MAXFeatures.dylib>
Then: install_name_tool -id @executable_path/MAXFeatures.dylib <out>; codesign --remove-signature <out>
(the signing runner re-signs it per device). Verify offset by content, not a hard-coded number, so a
new diyordeMax build is matched by its prologue rather than a stale offset.
"""
import sys, shutil

ORIG = bytes.fromhex("f44fbea9")              # stp x20,x19,[sp,#-0x20]! (prologue start)
RET  = bytes.fromhex("c0035fd6")              # ret
# Unique tail of _max_call_notify_install's prologue+once-guard, to locate the entry unambiguously:
#   <entry> fd7b01a9 fd430091 e80600d0 08612f91 29008052   (the once-guard adrp/add/mov w9,#1)
GUARD = bytes.fromhex("fd7b01a9fd430091e80600d008612f9129008052")

def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    src, dst = sys.argv[1], sys.argv[2]
    b = bytearray(open(src, "rb").read())
    i = b.find(GUARD)
    if i < 4:
        sys.exit("ERROR: _max_call_notify_install guard sequence not found; diyordeMax layout changed.")
    entry = i - 4
    if bytes(b[entry:entry+4]) != ORIG:
        sys.exit(f"ERROR: unexpected entry bytes {bytes(b[entry:entry+4]).hex()} at {entry}; aborting.")
    if b.find(GUARD, i+1) != -1:
        sys.exit("ERROR: guard sequence is not unique; refuse to patch blindly.")
    b[entry:entry+4] = RET
    open(dst, "wb").write(b)
    print(f"patched _max_call_notify_install entry @ file offset {entry}: {ORIG.hex()} -> {RET.hex()}")

if __name__ == "__main__":
    main()
