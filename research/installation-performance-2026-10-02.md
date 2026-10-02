# Installation performance: production measurements, 2 October 2026

Deployed backend changes and runner 2.1.0 to ruappstore.com. All comparisons below use
the same published VK 8.183 artifact (355), source SHA-256
`de9e0b45219cd956f7447ec4e6efbd3c22cb43d243d0205059bf03a38881cb69`.
Times describe server preparation through DELIVERABLE, before an iPhone downloads or installs the app.

## Changes

- zsign ZIP level 1, two concurrent runner actors, production lease polling every two seconds.
- Queue transactions lock each candidate job individually; one lease cannot lock the whole candidate batch.
- Ten-minute presigned source downloads, with the authenticated worker relay as a fallback.
  Object requests carry no worker authentication headers. Source SHA-256 checks remain mandatory.
- Private signed-file cache, bounded to 8 GiB with a 24-hour idle lifetime. Uploaded bytes are reused
  by verification and authorized downloads. Object ETag and local SHA-256 are checked before reuse;
  expired, missing or changed copies fall back to object storage.
- Signature, profile, team, device and artifact authorization checks remain in place.
  Unsatisfiable byte ranges return HTTP 416 on both cached and remote paths.
- Signing reports now record source download, signing and upload duration separately.

## Observations

| Build | Situation | Ready after | Signed IPA bytes | Verification job |
|---|---|---:|---:|---:|
| 70 | Before, device A | 119 s | 515,953,920 | See note below |
| 71 | Before, device B, queued behind another job | 170 s | 515,953,951 | See note below |
| 72 | Before, device C, empty queue | 105 s | 515,953,928 | See note below |
| 75 | Optimized, device C, empty queue | 99 s | 246,754,803 | 9 s from signed to ready |
| 76 | Final configuration, device A, parallel | 107 s | 246,754,662 | 9 s |
| 77 | Final configuration, device B, parallel | 105 s | 246,754,586 | 9 s |

Before the change, the signed-to-ready interval was 38, 29 and 30 seconds for builds 70–72.
The final pair was created together and both leases started at 22:19:50 UTC on 1 October.
Both passed independent verification and became DELIVERABLE. Cache hits were confirmed for
the first optimized pair (73–74). File size fell approximately 52.2%.

These are production observations, not a controlled throughput benchmark: the earlier requests
arrived at different times. Compression adds CPU work: the runner signing stage measured about
42 seconds after the change versus 25–27 seconds before. Removing the verification download and
reducing the customer's IPA size provide the main gains; parallel workers reduce queue waiting.

## Transfer and validation

A full authorized HTTPS download of build 73 to this workstation transferred 246,754,705 bytes
in 223.14 seconds (8.8 Mbit/s overall), with response headers after 3.98 seconds. Its SHA-256
matched the uploaded build. Range requests returned 206, an unsatisfiable range returned 416,
and a tampered signed URL returned 403. The temporary diagnostic installation was removed.

A separate 4 MiB static-file transfer took 1.49 seconds (22.5 Mbit/s overall). Nginx had no
explicit `limit_rate`, and the host traffic-control configuration showed no bandwidth shaper.
These observations do not establish the cause of the workstation's throughput or verify the
hosting plan's nominal 200 Mbit/s. An iPhone's download and installation have not been measured.

Validation: 410 backend tests passed with 2,770 assertions (`APP_URL=http://localhost`), PHP
formatting and syntax checks passed, and the Linux Docker runner suite passed, including actual
zsign compression/signature verification. The production health endpoint returned OK.

Backend source and runner source were retained on the server. The original runner container and
backend/source backups remain available under `/var/backups/storefront/optimization-20261002`
for rollback. Existing signed builds were retained for previously issued download links.

## Device warmup and signing cache, runner 2.2.0

Deployed full build warmup and the runner's persistent source/signing caches on 2 October.
Completing Apple registration starts up to three popular apps, ranked by installation requests
in the last 30 days; opening an app page also starts its full build. These requests do not create
installations. Customer requests promote pending builds to foreground priority. The runner leases
at most one background signing job at a time, leaving the second slot available for foreground work.
Prepared builds are reused only while their artifact version, embedded profile and certificate
remain valid. Revoked or expired profiles now explicitly prevent reuse.

Original IPA downloads are coalesced and hash-checked. Stable unpacked trees are isolated by source,
team, certificate, bundle mappings and zsign binary. Exclusive file locks protect mutation, and an
integrity manifest validates the entire previous tree, including zsign's metadata. Cold trees use
`zsign -f`; validated warm trees allow zsign's cache. Every resulting IPA still passes the full
signature, resource, entitlement and profile verification. The combined runner cache defaults to
8 GiB with a 24-hour idle lifetime. A cache problem falls back to ordinary preparation.

### Angry Birds Reloaded 3.3.18474

Sequential standalone signing runs used the same 656,341,107-byte source and the same valid
device profile on the production VPS. ZIP level was 1. No new installation or downloadable build
was created by this benchmark; download/upload, queue waiting and backend verification are excluded.

| Run | Signer total | zsign including ZIP | Output verification | Workspace / zsign cache |
|---|---:|---:|---:|---|
| Cache disabled | 59.0 s | 38.2 s | 10.5 s | miss / miss |
| First cached run | 62.1 s | 38.5 s | 10.9 s | miss / miss |
| Reused cached tree | 50.9 s | 34.5 s | 10.6 s | hit / hit |

Each output passed signature verification and remained approximately 656.3 MB. The original source
fetch took 33.5 seconds. The warm signer run saved 8.1 seconds (13.7%) against the uncached run;
initial cache creation added 3.1 seconds in this observation. This is one sequential comparison,
not a statistical benchmark. ZIP packing and complete output verification still take time.

The existing ready Angry Birds build was reused through `InstallationService::prewarm` in 40.3 ms.
This is backend build selection, not a measured iPhone download/install time. The user's three
popular-app warmup jobs were consumed by the new background worker with no failures; existing
deliverable builds were reused, and installation/build counts did not increase. The main route to
short perceived preparation is completing work before the install tap; the cache alone does not
make a first large-app preparation take 10–20 seconds.

Validation: 417 backend tests / 2,873 assertions passed with `APP_URL=http://localhost`; all 29
Linux runner tests passed with pinned zsign 1.1.2, including new device profiles, extension profiles,
corrupt-tree recovery, download coalescing and bounded eviction. Formatting, shell syntax and
patch whitespace checks passed. Production API returned HTTP 200, runner heartbeat reported 2.2.0,
and the background worker was active. Backend backups and the runner container inspection are in
`/var/backups/storefront/warmup-cache-20261002`; the previous container is retained as
`storefront-runner-before-warmup-20261002` for rollback.
