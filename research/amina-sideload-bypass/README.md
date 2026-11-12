# Амина (ru.mos.amina.app) force-update bypass — diyorde's method

Амина shows "please update" on any re-signed build because a native sideload/
integrity gate fails (mos.ru `/force-update` returns 426 server-side; the check
fail-closes). Diyorde's working sideload build does **NOT patch the app code** —
the Dart snapshot (App.framework/App) and the native Runner are byte-identical to
the clean IPA. Diyorde only **injects these 4 dylibs** and adds matching
`LC_LOAD_DYLIB @executable_path/...` entries to Runner:

- `Sideloadbypass1.dylib` (armv7+arm64) + `Sideloadbypass2.dylib` (arm64):
  fishhook rebinders (`_dyld_register_func_for_add_image`), opaque — defeat the
  native sideload/integrity/attest detection. These are what clear the update gate.
- `sideloadKeychainFix.dylib`: SecItemAdd/CopyMatching access-group fix.
- `sideloadFixerLol.dylib`: app-group container fix.

No phone-home URLs (safe). Pulled from diyorde's signed IPA on 2026-11-12 via
mitmproxy (see memory amina-force-update-20261112).

Verified 2026-11-12: re-signing diyorde's IPA with OUR cert (team 5CV985HSCG,
kept bundle id ru.mos.amina.app) → artifact 1044 on listing 69 → device-tested:
opens normally, no update prompt.

TODO: integrate injection into the signing pipeline so our own clean Амина (and
other sideload-gated apps) get these dylibs automatically on re-sign.
