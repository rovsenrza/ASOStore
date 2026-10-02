# Storefront signing runner

A small Swift program that re-signs uploaded IPAs for registered devices, with two concurrent jobs by default
(IMPLEMENTATION_PLAN §5.6, P6-RUN-01/02, D16). It runs on **Linux in Docker** and signs with
[zsign](https://github.com/zhlynn/zsign); no Mac is needed. It **pulls** work from the backend's
worker API over HTTPS; the backend never connects to the runner, and the runner never sees App Store
Connect credentials. Signing-certificate private keys exist only in the runner's identities folder.

## How a job runs

1. Every minute the runner sends a heartbeat with the signing identities in its identities folder
   (SHA-1, serial, team ID from the certificate's OU, expiry — never the key).
   The backend records them as `certificates` and uses them to pick a certificate for profiles.
2. It leases a `SignArtifactJob` whose certificate it holds, prioritizing customer requests over
   speculative builds (10-minute lease, renewed every 2 minutes). At most one speculative job
   runs per runner identity, leaving capacity for customer requests with the default concurrency of two.
3. It downloads the original IPA directly through a ten-minute object-store URL when available,
   falling back to the authenticated worker relay, and **refuses the job if the SHA-256 differs from the lease**.
4. It reuses a locked, hash-checked unpacked signing tree when available, derives entitlements from the device's ad hoc profile (application identifier
   fixed to the leased bundle and team), and runs zsign, which embeds the profile, signs `Frameworks/`
   and the app, and repacks with fast ZIP compression (level 1).
5. It unpacks the result and checks it (this replaces `codesign --verify`): every code page hash, the
   Info.plist, CodeResources and entitlements hashes, the CMS signature over the CodeDirectory, that the
   signer is the leased certificate and the team is the leased team, for the app and every framework
   and dylib, and that the embedded profile is the leased one (`CodeSignature.swift`).
6. It uploads the new IPA with its hash and reports the result. The backend then verifies the build
   independently (hash, bundle ID, profile UUID, team, device in the profile) before it can be installed.
7. The job folder is deleted whether the job succeeded or not.

App extensions (`PlugIns/*.appex`, `Extensions/*.appex`) are signed with their own profiles. The
lease lists each one under `nested` (`path`, `bundle_identifier`, `profile`); the backend provisions a
profile per bundle ID and enables the capabilities its entitlements need (Network Extensions, App
Groups, …). The lease's `bundle_identifier` may differ from the IPA's: the runner then re-identifies
the app and its extensions (Info.plist `CFBundleIdentifier`) before signing, so an app whose own ID
belongs to another Apple team can be signed as `com.ruappstore.*`. With extensions, zsign gets one
`-m` per profile and no `-e`, so each bundle takes its own profile's entitlements. An extension the
lease does not list, a watch app or an App Clip fails the job with `NESTED_PROFILE_REQUIRED`.

App Group identifiers cannot be created through the App Store Connect API: create the group in the
developer portal and assign it to the app's and the extensions' App IDs, or the profiles carry no
`com.apple.security.application-groups`.

The lease may list dylibs to inject under `inject_dylibs` (`name`, base64 `content`, optional
`weak`). The backend sends the launch-compatibility shim (`ios/compat-shim/RuStoreCompat.dylib`) for
apps that share through an App Group or keychain group, which otherwise quit on launch because the
vendor's original groups are not ours after re-signing. zsign copies each dylib into the app, adds a
load command and signs it. Injection happens only when a signing tree is built fresh — a warm tree
already carries the dylib — and the injected set is part of the tree's cache key, so an injected tree
is never reused for a plain sign and a new shim version rebuilds the tree. The report lists
`injected_dylibs`.

## Identities folder

One file pair per signing identity: `<name>.key` (PEM private key) and `<name>.cer` (the certificate
as the Apple developer portal downloads it) or `<name>.pem`. A key that does not match its certificate
is skipped and logged. Create the key and the certificate request on the server, so the key never
leaves it:

```sh
install -d -m 0700 /etc/storefront/signing && cd /etc/storefront/signing
openssl genrsa -out distribution.key 2048
openssl req -new -key distribution.key -out distribution.csr -subj "/CN=Storefront Distribution/C=AZ"
# Portal → Certificates → + → Apple Distribution → upload distribution.csr → download as distribution.cer
chown 10001:10001 distribution.key distribution.cer && chmod 0400 distribution.key distribution.cer
```

An identity exported from Keychain Access as `.p12` converts with
`openssl pkcs12 -in id.p12 -nocerts -nodes -out distribution.key` and
`openssl pkcs12 -in id.p12 -clcerts -nokeys -out distribution.pem` (add `-legacy` on OpenSSL 3 for old exports).

Keep an offline, encrypted backup of every `.key`: without it the certificate cannot sign, and a new
certificate means every installed app must be reinstalled.

## Set-up (Docker, same server as the backend)

1. On the backend: `php artisan runner:create signer-1` prints `STOREFRONT_RUNNER_KEY_ID` and
   `STOREFRONT_RUNNER_SECRET` once. Put them in `/etc/storefront/runner.env` (mode 0600) with
   `STOREFRONT_RUNNER_BASE_URL=https://…`.
2. Build and check the identities:
   ```sh
   docker build -t storefront-runner runner
   docker run --rm -v /etc/storefront/signing:/run/secrets/signing:ro storefront-runner --list-identities
   ```
3. Run it:
   ```sh
   docker run -d --name storefront-runner --restart unless-stopped \
     --env-file /etc/storefront/runner.env \
     -v /etc/storefront/signing:/run/secrets/signing:ro \
     -v storefront-runner-work:/var/lib/storefront-runner/work \
     --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
     storefront-runner
   ```
   Logs: `docker logs -f storefront-runner`. The container runs as uid 10001 and needs no inbound port.

| Variable | Meaning |
|---|---|
| `STOREFRONT_RUNNER_BASE_URL` | Backend origin, `https://…` (no path) |
| `STOREFRONT_RUNNER_KEY_ID`, `STOREFRONT_RUNNER_SECRET` | From `runner:create` |
| `STOREFRONT_RUNNER_IDENTITIES_DIR` | Identities folder (image default `/run/secrets/signing`) |
| `STOREFRONT_RUNNER_WORK_DIR` | Private scratch folder (image default `/var/lib/storefront-runner/work`) |
| `STOREFRONT_RUNNER_ZSIGN` | zsign executable (default `zsign` from PATH) |
| `STOREFRONT_RUNNER_ZIP_LEVEL` | ZIP compression 0–9, default 1 |
| `STOREFRONT_RUNNER_CONCURRENCY` | Concurrent jobs 1–4, default 2 |
| `STOREFRONT_RUNNER_POLL_SECONDS` | Lease polling interval, default 10 |
| `STOREFRONT_RUNNER_CACHE_ENABLED` | Set to `0` to disable source and signing caches; enabled by default |
| `STOREFRONT_RUNNER_CACHE_DIR` | Persistent private cache, default `$STOREFRONT_RUNNER_WORK_DIR/.cache` |
| `STOREFRONT_RUNNER_CACHE_MAX_BYTES` | Combined source/signing cache budget, default 8 GiB |
| `STOREFRONT_RUNNER_CACHE_TTL_SECONDS` | Cache lifetime since last use, default 86400; maximum 604800 |
| `STOREFRONT_RUNNER_ALLOW_HTTP=1` | Local development only |

The Dockerfile pins zsign by version and SHA-256; to upgrade, change `ZSIGN_VERSION` and both checksums,
then run the test stage and install one build on a real device before deploying.

The backend keeps uploaded signed IPAs in a private file cache for 24 hours, bounded to 8 GiB.
Verification and authorized customer downloads reuse this copy after checking its SHA-256 and the
object-store ETag. A missing, changed, corrupt or expired copy falls back to object storage. Configure
`STOREFRONT_ARTIFACT_CACHE_ENABLED`, `STOREFRONT_ARTIFACT_CACHE_MAX_BYTES` and
`STOREFRONT_ARTIFACT_CACHE_TTL_SECONDS` on the backend to adjust these defaults. Authorization and
HTTP Range support are unchanged. Runner reports include signing, source download and upload times.

The runner also caches original IPAs and unpacked signing workspaces in its persistent work volume.
Downloads for the same SHA-256 are coalesced and validated; each job pins its source with a hard link
(or a copy). A signing workspace is keyed by the source hash, team, certificate, bundle mappings and
zsign executable hash. Its complete last verified contents, including `.zsign_cache`, are checked
before reuse. Changed, expired or incomplete trees are rebuilt from the source. An exclusive file lock
protects the stable app path while zsign re-signs it with the current job's profiles. Failed signing or
verification discards the workspace. zsign always runs with `-f`: its 1.1.2 hash cache incorrectly
includes the main executable in the resource seal on repeated signing. Source downloads and unpacked
trees still reuse their caches. Final verification checks resource entry hashes and excludes the main
executable from CodeResources, in addition to the code/CMS/profile checks. Apple evaluates trust and
platform requirements on the device. Reports identify `signature_policy=fresh-resource-seal-v1`
and `resource_seal=verified`; `zsign_cache` must be `miss` even when `workspace_cache` is `hit`.
TTL/LRU eviction after jobs respects active locks and the combined disk budget; job output folders
remain temporary. Reports expose `source_cache`, `workspace_cache`, `zsign_cache`, `zsign_seconds`
and `verification_seconds`, so cold and warm runs can be compared.

On the backend, opening an app page starts its full build for an eligible current device; completing
Apple registration starts up to three popular non-storefront apps, ranked by actual installation
requests in the last 30 days. Warm builds do not create installation records or authorize downloads.
Set `STOREFRONT_SIGNING_WARMUP_ENABLED=false` to disable, or
`STOREFRONT_SIGNING_WARMUP_POPULAR_LIMIT=0` to keep only page-triggered preparation. App/device
requests are throttled for ten minutes and reuse pending or deliverable builds; normal expiry and
revocation checks remain in the install flow. Run `ops/systemd/storefront-queue-background.service`
alongside the Apple/files workers, or include `background` last in a shared worker's queue list.

## Development

```sh
docker build --target test runner   # full suite on Linux: signs the DemoApp fixture with zsign and verifies it
swift build && swift test            # on a Mac; the zsign tests run only if zsign and openssl are installed
```

The fixture `Tests/RunnerCoreTests/Fixtures/DemoApp.ipa` is the unsigned output of
`scripts/export-demo-ipa.sh`. The tests sign it with a throwaway certificate whose issuer is named
like Apple's WWDR G3 intermediate, because zsign attaches the intermediate by issuer name.

Against a local backend: `STOREFRONT_RUNNER_ALLOW_HTTP=1 STOREFRONT_RUNNER_BASE_URL=http://127.0.0.1:8000 … swift run`.
If the runner goes offline, its jobs return to the queue when their lease expires and are signed
once it is back (the job's idempotency key prevents a second build).
