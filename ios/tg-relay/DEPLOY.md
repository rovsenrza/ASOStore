# TGRelay — deploy

`TGRelay.dylib` is injected into re-signed Telegram (`ph.telegra.Telegraph`) so it works on Russian
internet **without a VPN** and **without the customer changing any setting**. It forces every MTProto
connection through our fake-TLS MTProxy (mtg on `www.ruappstore.com:443`, hidden behind the site —
see memory `mtproxy-prod-20261028`) by swizzling `-[MTApiEnvironment socksProxySettings]`. If the
user set their own proxy, theirs wins. It also writes a diagnostics log so we can see field problems
(e.g. the TSPU ~16 KB freeze as a transport read error).

## 1. Signing side (this repo — already wired)
- `CompatShim` injects `TGRelay.dylib` for bundles matching
  `storefront.signing.relay_hook.tg_bundle_ids` (default `ph.telegra.Telegraph`).
- Telegram still gets the normal `RuStoreCompat.dylib` (it uses app groups), but **none** of the
  MAX/VK relay dylibs. Not a per-device hook — the proxy is identical for everyone, so no UDID routing.
- Build on a Mac with Xcode: `cd ios/tg-relay && make` (fat arm64+arm64e). Rebuild + commit whenever
  `TGRelay.m` changes; the runner ships whatever bytes are on disk at signing time.

## 2. Server (prod — already live)
- The MTProxy itself is already deployed (commit 14c3ade): mtg behind the nginx `:443` SNI stream.
- The diagnostics endpoint `POST /api/v1/tg-relay/diag` is a normal Laravel route
  (`TelemetryController::tgDiag`) — it logs to `storage/logs/laravel-*.log`, no new service or nginx
  change. Read it: `grep tg-relay.diag storage/logs/laravel-$(date +%F).log`.

## 3. Rollout (device)
1. Expire Telegram's (app 349) DELIVERABLE builds and `InstallationService::prewarm($device, $app)`
   so the next install re-signs with `TGRelay.dylib`. Keep all extensions (`--nested`).
2. Install on the customer's device, open Telegram, sign in.
3. The internal log is at `<container>/Library/Caches/tgrelay.log`; the same lines go to syslog
   (`[TGRelay] ...`) and to the diag endpoint. Expect:
   `init`, `proxy settings built`, `proxy getter hooked`, then `proxy_applied forced` in the diag log.
4. `tcp close err=...` lines mean the proxy connection is failing (likely TSPU on a Contabo IP) —
   that is the signal to move mtg to a non-Contabo VPS (the link/host stays `www.ruappstore.com`).

## Notes
- The secret in `TGRelay.m` (`kProxySecretHex`) must match `/etc/mtg/config.toml`. If the proxy
  secret is rotated, rebuild the dylib.
- Moving the proxy to a different server is a DNS change only (host is `www.ruappstore.com`), no
  rebuild — unless the secret changes.
