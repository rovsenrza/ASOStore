# Sideload / integrity bypass dylibs (diyorde's method)

Four generic dylibs that defeat the native sideload / integrity / App-Attest gate some
re-signed apps (bank / gov) enforce — the app installs and launches, but login or a feature
is blocked because the vendor's RASP detects the re-sign. Diyorde clears this WITHOUT patching
app code by injecting these four and keeping the real bundle id:

- `Sideloadbypass1.dylib` (armv7+arm64) + `Sideloadbypass2.dylib` (arm64): fishhook rebinders
  (`_dyld_register_func_for_add_image`), opaque — the integrity/attest/sideload bypass.
- `sideloadKeychainFix.dylib`: SecItemAdd/CopyMatching access-group fix.
- `sideloadFixerLol.dylib`: app-group container fix.

Injected by `CompatShim::sideloadBypassDylibsFor` for the vendor bundle ids in
`storefront.signing.sideload_bypass.bundle_ids`. An app on that list gets these four INSTEAD of
RuStoreCompat.dylib (their own keychain/app-group fixers stand in for the shim). The runner
injects each with a non-weak load command (zsign `-l`) and re-signs it with the bundle.

Source: pulled from diyorde's signed Амина IPA 2026-11-12 (see research/amina-sideload-bypass,
memory amina-force-update-20261112). Byte-identical copies live in research/amina-sideload-bypass.
