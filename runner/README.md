# Storefront signing runner

A small Swift program that re-signs uploaded IPAs for one registered device at a time
(IMPLEMENTATION_PLAN §5.6, P6-RUN-01/02, D16). It runs on **Linux in Docker** and signs with
[zsign](https://github.com/zhlynn/zsign); no Mac is needed. It **pulls** work from the backend's
worker API over HTTPS; the backend never connects to the runner, and the runner never sees App Store
Connect credentials. Signing-certificate private keys exist only in the runner's identities folder.

## How a job runs

1. Every minute the runner sends a heartbeat with the signing identities in its identities folder
   (SHA-1, serial, team ID from the certificate's OU, expiry — never the key).
   The backend records them as `certificates` and uses them to pick a certificate for profiles.
2. It leases the oldest `SignArtifactJob` whose certificate it holds (10-minute lease, renewed every 2 minutes).
3. It downloads the original IPA and **refuses the job if the SHA-256 differs from the lease**.
4. It unpacks the IPA, derives entitlements from the device's ad hoc profile (application identifier
   fixed to the leased bundle and team), and runs zsign, which embeds the profile, signs `Frameworks/`
   and the app, and repacks.
5. It unpacks the result and checks it (this replaces `codesign --verify`): every code page hash, the
   Info.plist, CodeResources and entitlements hashes, the CMS signature over the CodeDirectory, that the
   signer is the leased certificate and the team is the leased team, for the app and every framework
   and dylib, and that the embedded profile is the leased one (`CodeSignature.swift`).
6. It uploads the new IPA with its hash and reports the result. The backend then verifies the build
   independently (hash, bundle ID, profile UUID, team, device in the profile) before it can be installed.
7. The job folder is deleted whether the job succeeded or not.

Not yet supported: apps with extensions, watch apps or App Clips (each nested bundle needs its own
profile). Such jobs fail with `NESTED_PROFILE_REQUIRED`.

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
| `STOREFRONT_RUNNER_POLL_SECONDS` | Lease polling interval, default 10 |
| `STOREFRONT_RUNNER_ALLOW_HTTP=1` | Local development only |

The Dockerfile pins zsign by version and SHA-256; to upgrade, change `ZSIGN_VERSION` and both checksums,
then run the test stage and install one build on a real device before deploying.

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
