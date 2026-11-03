# Supercell anti-tamper bypass — RE project plan

**Created:** 2026-10-14 · **Status:** F0/F1 done 2026-10-15 — gate: STOP (see research/supercell/F0-F1-findings-2026-10-15.md) · **Pilot:** Brawl Stars (com.supercell.laser, artifact 617)
**Targets (apply after pilot):** Clash of Clans (com.supercell.magic, art 618, listing 275), Clash Royale (com.supercell.scroll, art 619, listing 444), Brawl Stars (listing 443).

> No guarantee of success — Supercell's protection is among the hardest on iOS, and every game update will require redoing the patch. This plan is time-boxed with go/no-go gates so we can bail early if intractable.

## What we know (evidence)
- All three crash immediately on a normal (non-jailbroken) iPhone after our re-sign: `EXC_BAD_ACCESS (SIGKILL)`, termination namespace **CODESIGNING / "Invalid Page"**, triggered thread `pc = lr = sp = 0x123456789a00` (deliberate poison scribble), empty backtrace.
- Not our signing: VK Музыка, MAX, Avtodor re-signed by the same runner run fine.
- `Scid.framework/Scid` (Supercell ID) embeds a `cdhashes` plist key and the original Team ID `6WX5RKLG95` → the guard compares the running app's cdhash / Team ID to embedded expected values.
- The sentinel 0x123456789a00 is NOT a static constant / movz-movk immediate in the main `laser` binary — it is computed at runtime by obfuscated code (mangled RecaptchaEnterprise-style symbols, e.g. `wq6E05zJ`, `x9hbuIve`). No single branch/pointer to NOP was found in the triage.

## Key advantage (why this is feasible at all)
We control signing, so on a non-jailbroken device we can still instrument the pilot build:
- Add `get-task-allow` entitlement (+ a development cert/profile for device 1) → attach **LLDB** over USB.
- Inject a **Frida gadget** dylib via the runner's existing dylib-injection path (same mechanism as the compat shim) → dynamic hooking without jailbreak.
Decision: use **both** LLDB and Frida for recon.

## Tooling
- Mac: Xcode/LLDB (have), **Ghidra** (free decompiler — install), Frida + frida-tools, the existing signing runner for producing instrumented/patched build variants, `devicectl` for install + crash logs.
- Device: user's iPhone 16 Pro (device 1, UDID 00008140-00145D163C69801C), connected + unlocked, in agreed time windows.

## Phases (each has a go/no-go gate)
- **F0 — Instrumented pilot build.** Sign Brawl Stars with `get-task-allow` + injected Frida gadget; install; confirm LLDB attach AND Frida connect. Set up Ghidra project on the decrypted `laser` + `Scid`. *Gate:* can we attach? If not → static-only path (much higher cost).
- **F1 — Defeat anti-debug.** Instrumentation itself may trip anti-debug (ptrace PT_DENY_ATTACH, sysctl KERN_PROC, csops). Neutralize these first (hooks/patches) so recon is possible.
- **F2 — Locate the poison site.** Watchpoint on sp/lr corruption or break at the scribble; backtrace to the integrity-check caller. Identify what it reads (cdhash via csops, Team ID from embedded profile, receipt, jailbreak markers).
- **F3 — Enumerate all checks.** Expect several: startup + periodic timer + scattered inline. List every integrity/anti-tamper/anti-debug routine and its trigger.
- **F4 — Build the bypass.** Prefer static binary patches so the shipped build is self-contained (no runtime agent): patch main `laser` + `Scid.framework/Scid` check routines to always pass.
- **F5 — Validate.** Full launch, Supercell ID / Game Center login, actual gameplay, survive a reboot, confirm periodic re-checks don't kill it later. Device test on device 1.
- **F6 — Productionize.** Fold the patch into a repeatable script (like scripts/rebrand-ipa.py and the VK getter patch), wire into the signing pipeline (or a pre-sign transform), and write a **per-version update runbook** (every game update ⇒ re-locate + re-patch).

## Risks
- Success not guaranteed; obfuscated multi-layer guard.
- Anti-debug may block dynamic recon (handled in F1, but could be hard).
- Per-version fragility: breaks on every game update; ongoing maintenance / arms race.

## Decisions taken (2026-10-14)
- Recon: LLDB + Frida (both).
- Catalog: leave listings 275/443/444 visible for now (do NOT hide).
- Pilot only Brawl Stars first; apply to CoC + Royale only if the pilot yields a working, self-contained patch.

## Next step
Kick off **F0** in a dedicated session with the device connected: produce the instrumented Brawl Stars build and confirm we can attach. This is the first go/no-go gate.
