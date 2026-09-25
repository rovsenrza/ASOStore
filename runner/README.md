# Storefront signing runner

A small macOS program that re-signs uploaded IPAs for one registered device at a time
(IMPLEMENTATION_PLAN §5.6, P6-RUN-01/02). It **pulls** work from the backend's worker API over
HTTPS; the backend never connects to the Mac, and the Mac never sees App Store Connect credentials.
Signing-certificate private keys exist only in this Mac's Keychain.

## How a job runs

1. Every minute the runner sends a heartbeat with the signing identities in its Keychain
   (SHA-1, serial, team ID from the certificate's OU, expiry — never the key).
   The backend records them as `certificates` and uses them to pick a certificate for profiles.
2. It leases the oldest `SignArtifactJob` whose certificate it holds (10-minute lease, renewed every 2 minutes).
3. It downloads the original IPA and **refuses the job if the SHA-256 differs from the lease**.
4. It unpacks the IPA, embeds the device's ad hoc profile, derives entitlements from the profile
   (application identifier fixed to the leased bundle and team), signs `Frameworks/` first and the app last,
   and runs `codesign --verify --strict`.
5. It uploads the new IPA with its hash and reports the result. The backend then verifies the build
   independently (hash, bundle ID, profile UUID, team, device in the profile) before it can be installed.
6. The job folder is deleted whether the job succeeded or not.

Not yet supported: apps with extensions, watch apps or App Clips (each nested bundle needs its own
profile). Such jobs fail with `NESTED_PROFILE_REQUIRED`.

## Set-up

1. On the backend: `php artisan runner:create mac-mini-1` prints `STOREFRONT_RUNNER_KEY_ID` and
   `STOREFRONT_RUNNER_SECRET` once.
2. On the Mac, create a dedicated user (e.g. `storefront`) and a dedicated Keychain holding only the
   Apple Distribution identity of the team:
   ```sh
   security create-keychain -p "$KEYCHAIN_PASSWORD" signing.keychain-db
   security import distribution.p12 -k signing.keychain-db -P "$P12_PASSWORD" -T /usr/bin/codesign
   security set-key-partition-list -S apple-tool:,apple: -k "$KEYCHAIN_PASSWORD" signing.keychain-db
   ```
3. Build and install:
   ```sh
   swift build -c release
   sudo cp .build/release/storefront-runner /usr/local/bin/
   STOREFRONT_RUNNER_KEYCHAIN=~/Library/Keychains/signing.keychain-db storefront-runner --list-identities
   ```
4. Fill in `launchd/az.storefront.runner.plist` and load it as a LaunchAgent of that user.
   Logs go to the unified log (`log stream --predicate 'subsystem == "storefront.runner"'`).

| Variable | Meaning |
|---|---|
| `STOREFRONT_RUNNER_BASE_URL` | Backend origin, `https://…` (no path) |
| `STOREFRONT_RUNNER_KEY_ID`, `STOREFRONT_RUNNER_SECRET` | From `runner:create` |
| `STOREFRONT_RUNNER_KEYCHAIN` | Dedicated signing Keychain (recommended) |
| `STOREFRONT_RUNNER_WORK_DIR` | Private scratch folder (0700) |
| `STOREFRONT_RUNNER_POLL_SECONDS` | Lease polling interval, default 10 |
| `STOREFRONT_RUNNER_ALLOW_HTTP=1` | Local development only |

## Development

```sh
swift build
swift test      # request signing (vector shared with the PHP middleware), identities, entitlements
```

Against a local backend: `STOREFRONT_RUNNER_ALLOW_HTTP=1 STOREFRONT_RUNNER_BASE_URL=http://127.0.0.1:8000 … swift run`.
If the runner goes offline, its jobs return to the queue when their lease expires and are signed
once it is back (the job's idempotency key prevents a second build).
