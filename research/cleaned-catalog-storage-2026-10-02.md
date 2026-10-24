# Cleaned catalog publication and storage cleanup — 2026-10-02

17 of 19 supplied IPAs are published: 13 existing listings replaced and 4 new listings created. Original branded names and icons are in the public catalog. OK and ЮMoney remain unpublished pending the user’s choice about removing encrypted optional notification/widget components. No answer is inferred from elapsed time.

## Catalog results

| App | Version / build | Catalog ID | New artifact ID | Result |
| --- | --- | --- | --- | --- |
| Одноклассники | 11.63.1 / 20364322 | 151 | — | Pending user choice |
| ОК Знакомства | 1.7 / 1273 | 348 | 356 | Published; new |
| Telegram | 12.9.2 / 34474 | 349 | 357 | Published; new |
| VK Mail | 3.92.0 / 90407 | 152 | 358 | Published; updated |
| VK Play | 3.16.16 / 729 | 90 | 359 | Published; updated |
| VK Видео | 8.183 / 2124 | 57 | 360 | Published; updated |
| VK Знакомства | 1.33 / 1274 | 350 | 361 | Published; new |
| Альфа-Банк | 2.0.0 / 2 | 82 | 362 | Published; updated |
| Дзен | 26.6.3 / 448 | 148 | 363 | Published; updated |
| МТС Банк | 11.0.0 / 2 | 75 | 364 | Published; updated |
| MAX | 26.17.3 / 31710 | 127 | 365 | Published; updated |
| Маруся | 1.81.0 / 1558 | 149 | 366 | Published; updated |
| Новикомбанк | 1.10.3 / 1.10.3.25 | 210 | 367 | Published; updated |
| СберKids | 5.12.0 / 2 | 351 | 368 | Published; new |
| Т-Банк | 7.13.1 / 1 | 81 | 369 | Published; updated |
| Уралсиб Онлайн | 1.0 / 2 | 240 | 370 | Published; updated |
| ЮMoney | 11.12.0 / 20 | 86 | — | Pending user choice |
| Юла | 6.0 / 41243 | 150 | 371 | Published; updated |
| Яндекс Музыка | 809 / 235988 | 167 | 372 | Published; updated |

New listings: ОК Знакомства, Telegram, VK Знакомства and СберKids. Existing listing IDs are preserved. Bank aliases А-Ключ, LunarCode, PriceDream and Пять монет are represented by their actual bank brands in the updated catalog. The displayed version comes from the IPA plist, not its sometimes outdated filename.

Sources are customer-provided, modified IPAs; branded catalog presentation does not represent them as untouched vendor packages. Each published IPA passed the full server IpaInspector and CLEAN malware scan. Its stored S3 bytes were streamed and verified against SHA-256 before publication. Same-version replacements use exact pinned old artifact IDs/hashes, then the normal independent review, compatibility and publication services. Old PUBLISHED artifacts become EXPIRED/SUPERSEDED; affected signed builds are expired by the normal service. Database/audit records are retained.

### Icon provenance

Most icons were decoded from the supplied app’s original primary icon. Small original icons retain their native resolution rather than being enlarged. Telegram and Yandex Music artwork came from Apple’s bundle lookup. Three disguised bank icons were replaced with official assets:

- [Альфа-Банк PWA icon](https://alfaonline.servicecdn.ru/public/s3/static/newclick/pwa/icons/512_512.png)
- [НОВИКОМ icon](https://static.rustore.ru/apk/1705373631/content/ICON/f01a8154-7c1a-4ee3-a265-7669630e8883.png), from the RuStore listing linked by [the bank](https://novikom.ru/app/)
- [Уралсиб PWA icon](https://online.uralsib.ru/static/appIcons/icon-192x192.png)

### Two pending IPAs

The supplied OK package contains encrypted LibverifyExt and OKApplovinAdServices binaries. LibverifyExt is a dependency of the optional ok-nse notification extension; it is not the main app’s Libverify SDK. The supplied ЮMoney package has encrypted NotificationService, SbpNotificationContent, WidgetsExtension and WidgetsColorIntent extensions. The inspector correctly rejects these supplied packages. Cryptid flags were not falsified.

Separate proposals remove those optional components (and OK’s separate advertising module). All remaining archive entry contents are byte-for-byte unchanged; both proposed archives pass CRC checks and the full server inspection with CLEAN scans and no compatibility issues. These proposals have not been published or substituted for the local supplied IPAs. Notification/widget functionality would be reduced; the user has been asked to choose. Main app startup on an iPhone has not been tested.

Prepared proposal hashes:

- OK: `75f978aa3e375fd8a7efbd169f26f4cbb8fa7f39074a10eb1596832ada0934b1`
- ЮMoney: `1deab7b26def456368012db3fca35d6238fabaf38002ef0c042bf6000201e111`

To continue after the user selects removal, import the hash-pinned `manifest-optional.json` with the prepared inspection reports, verify the two published replacements, and remove their private staging IPA copies. If the user selects preservation, do not publish the proposals and remove the private proposal copies from the VPS. Original local IPA files and backups remain available.

## Applied storage cleanup

| Storage category | Entries removed | Logical bytes removed |
| --- | ---: | ---: |
| S3 obsolete/orphan objects | 29 | 3,154,705,482 |
| S3 abandoned multipart attempts | 6 | 5,206,179,840 |
| VPS verified legacy IPA copies | 256 | 41,126,112,745 |
| Unreferenced old catalog media | 271 | 24,257,371 |

S3 objects and abandoned multipart parts removed total **8,360,885,322 bytes (8.36 GB)**. The multipart attempts belonged to known SIGNING_FAILED builds and had stopped receiving parts the previous day; exact upload IDs and unchanged part lists were verified before aborting. No multipart uploads remained at the verification snapshot.

All 256 removed legacy IPA copies were either obsolete terminal artifacts or verified S3 duplicates. Single-part MD5 or rclone multipart MD5 metadata was compared against the local bytes; a streamed remote checksum was used when metadata was insufficient. Live references are checked again before deletion, and object ETags/sizes are pinned. No planned deletion was skipped. No active application, usable signed build, useful warm cache, credentials, or database backup was removed.

Additional VPS cleanup:

- 14 obsolete inspection IPA copies in `/tmp`: 2,171,921,043 bytes.
- 17 published batch staging IPA copies: 2,167,120,516 bytes.
- Two superseded private proposal input copies: 379,734,563 bytes; originals remain local.
- Two old import copies: 31,196,475 bytes; one obsolete artifact and one SHA-256-verified S3 duplicate.
- Old terminal-artifact symlink and empty legacy subdirectories removed without following symlinks.
- Two stopped old runner containers and two unused test image tags removed; unused Docker build cache pruned. Docker reported 6.57 GB of cache reclamation; this apparent counter is not added to the measured filesystem delta.
- Two stale self-matching rclone polling shells stopped. No actual transfer was interrupted.

Measured filesystem use fell from **60,552,945,664** bytes at the checkpoint after Docker cleanup to **16,560,848,896** bytes after artifact cleanup and preparation of the two pending proposals: **43,992,096,768 bytes freed (~44.0 GB)**. Final available space: **86,310,469,632 bytes (~86 GB)**. Measurements exclude further savings from Docker cleanup before the checkpoint.

The only private staging IPAs retained by this batch are the two prepared proposals awaiting the user’s feature choice. Small manifests, inspection reports, deletion plans/results and the pre-publication database backup are retained for recovery/audit. Other import tooling/account sessions remain available.

## Verification

- 21 standalone storage protection checks pass, including shared active references, in-flight/retrying builds, quarantined artifacts, fresh unreferenced uploads, unsafe paths, multipart ETag handling and abandoned multipart policy.
- PHP syntax checks pass for both operational scripts.
- All 17 public app detail endpoints and original icon URLs returned HTTP 200 with matching names.
- After cleanup, all 17 new catalog entries, icons and S3 source objects were reverified.
- S3 snapshot: 371 complete objects, 74,966,584,727 bytes; all 334 published artifacts and 37 DELIVERABLE build rows have matching-size objects. No missing objects. Concurrent new customer activity can increase the object count; fresh or active files are protected.
- Public health endpoint and the live runner/queue services remain healthy.
- No iPhone launch test was performed. Customer installation still performs normal device-specific re-signing.

## Operational files and memory

Added `scripts/sync-cleaned-catalog.php`, `scripts/prune-artifact-storage.php` and `scripts/tests/test_prune_storage.php`. Existing backend/runner source changes in the worktree are from earlier work and were preserved. Private batch data is under `.local/catalog-sync-20261002/`, intentionally excluded from codebase-memory and Git; it contains the exact manifests, hashes and audit reports. This document and operational source files are covered by the full persistent repository index. Generated memory artifacts accompany the related changes.
