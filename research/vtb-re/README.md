# VTB "Altai" (appbankRU) mask RE — 2026-10-29

Target: ВТБ Онлайн New/Old = real VTB disguised by appbankRU (codename Altai, bundle com.matila.EventPulse).
Prod artifact 589 main binary `Altai` sha256 5f3e697d032c6aedc8950c5e49230212ea5fc9fa3cc9b567be48bdb3f9d4b8f0, 15 MB arm64, baddr 0x100000000.

## Mask mechanism (confirmed)
- `Altai.StringsMaskingManager` Swift class (type metadata 0x100ba0628). Held as a `stringsMaskingManager` property by many routers. Dispatched via Swift protocol-witness tables → NO static code xrefs (Ghidra full analysis: 0 functions resolve as users of the class).
- Remote control `https://open-api.deceptium.ru` — __cstring 0x100b313f0; URL getter leaf FUN_100a36fc0 (returns the pointer), wrapped by FUN_100a3708c. The network client that consumes it is reached by indirect dispatch (no static callers).
- `altai://altaiapp.com/i/welcomes` (0x100b05aa0) = appbankRU welcome-screen deeplink; handled in the altai-scheme router FUN_100349c8c (compares scheme=="altai" 0x6961746c61, matches the welcomes URL, sets a UserDefaults "shown" flag). This is the welcome banner, NOT the core cover gate.
- Anti-tamper: GIBMobileSdk (Group-IB) IntegrityChecker/JailbreakChecker (identitySignatureCheck/isAppPirated/getMachOFileHashValue), PulseAntiFraud, JailBreakFridaDetection, Dynatrace.

## Tooling (reusable)
- `arm64-string-xref-scanner.py`: decode ADRP+ADD / BL in __text to find xrefs/callers Ghidra misses on Swift binaries. Edit TEXT_OFF/VMBASE/targets.
- `DecompileMask2.java`: Ghidra headless postScript — BFS up the call graph from seed addresses and decompile.
- Full Ghidra analysis (no REA 330s timeout): `JAVA_HOME=/opt/homebrew/opt/openjdk@21 ghidra_12.1.4/support/analyzeHeadless <absproj> altai589 -import <abs>/altai589.bin -scriptPath <abs> -postScript <Script>.java` (keep project; re-query with `-process altai589.bin -noanalysis`). Ghidra 12 needs PyGhidra for .py scripts → use .java GhidraScript.

## Verdict
Not an Avito-style single-branch patch. The cover↔real decision is Swift-witness-dispatched + remote (deceptium.ru) + UserDefaults, guarded by commercial anti-tamper. Same class as WildGear (multi-day, likely needs dynamic/Frida with anti-tamper neutralized first). Pragmatic path: source a clean (non-appbankRU) VTB decrypt.
