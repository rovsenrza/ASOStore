# IPA cleaner

Finds and removes modules injected into supplied IPAs — promotional pop-ups, Telegram channel gates, tweaks — and fixes the metadata errors such sources leave behind. Standard library Python 3.9+, no dependencies; `bsdtar` (libarchive-tools) only for archives that are really RAR.

```sh
python3 tools/ipa-cleaner/ipa_clean.py analyze App.ipa                       # JSON report
python3 tools/ipa-cleaner/ipa_clean.py clean App.ipa Clean.ipa --recommended # reviewed promotions, patches, Info.plist
python3 tools/ipa-cleaner/ipa_clean.py clean App.ipa Clean.ipa --remove 'Payload/App.app/tweak.dylib' \
        --remove-extension 'Payload/App.app/PlugIns/Widget.appex/' --fix-metadata --report report.json
python3 tools/ipa-cleaner/ipa_clean.py batch ipas-local --report report.json [--apply] [--opt-in ymnight-mod]
python3 -m unittest discover -s tools/ipa-cleaner/tests
```

Exit status 0 is success, 2 a refusal (the JSON on stdout explains it), 1 an unexpected error.

## What the analysis reports

| Category | Meaning | `--recommended` |
|---|---|---|
| `promotion` | Matches a reviewed rule in `rules.json`: file name **and** every content marker | removed |
| `optional-mod` | A mod whose pop-up is built in; removing it removes the mod | only with `--opt-in` or `--remove` |
| `suspected-hook` | Appended load nobody uses symbols from, with hook markers (Substrate, Logos, fishhook…) — often a sideload fix the app needs | only with `--remove` |
| `hook-runtime` | A bundled Substrate/ElleKit; tweaks reach it at runtime, so it only goes together with all of them | only with `--remove` |
| `suspected-promotion` / `unreferenced-library` | Unused appended load with promotional markers / outside `Frameworks/` | only with `--remove` |
| `encrypted-component` | A FairPlay-encrypted binary: inspection rejects the IPA while it is there | only with `--remove` |

Also reported: app extensions (encrypted ones block publication; `--remove-extension` drops one with the frameworks only it used), hash-pinned library patches from `rules.json`, `Info.plist` problems (`CFBundleIdentifier` ending in a dot, `MinimumOSVersion` below the executable's own minimum) and the app's own ad SDKs (reported, never touched).

## Safety

- No bundled code is executed.
- A load command is removed only when it comes after every library ordinal its binary references, in every slice, so code, data and imports keep their bytes. Loads are resolved by path and by dyld install name.
- Untouched archive records are copied verbatim; only changed entries are recompressed.
- The written copy is read back: CRCs, app identity, every kept load still resolving, every retained entry unchanged.
- Library patches apply only to the exact reviewed build (SHA-256 before and after).
- Every output must be signed again for its device (the signing runner does this).

Validation on 2 October 2026: for the 19 IPAs cleaned by hand that day, `--recommended` (with `--keep-metadata`, and `--opt-in ymnight-mod` for Yandex Music) reproduces the reviewed outputs byte for byte, and selecting the eight flagged modules of the SberBank IPA with `--fix-metadata` reproduces published artifact 373.

## In the product

Inspection (`ArtifactInspectionService`) stores the analysis under `inspection.cleaning`. In the admin panel, «Очистить IPA…» on an artifact queues `CleanArtifactJob` (`POST /api/v1/admin/artifacts/{id}/clean`): the cleaned copy becomes a new artifact (`derived_from`, `cleaning_report`) that is inspected and reviewed again; the source leaves the review queue, and a published source stays live until its copy is published. `php artisan ipa:analyze [IDs] [--status=PUBLISHED] [--limit=N]` adds the analysis to IPAs stored before this existed. Settings: `STOREFRONT_IPA_CLEANER_*` in `backend/config/storefront.php`.
