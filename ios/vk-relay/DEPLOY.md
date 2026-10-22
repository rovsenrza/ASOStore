# VK notification relay — deploy

Mirrors the MAX relay ([ios/max-relay](../max-relay)). VK has **no** UserNotifications
entitlement block (it registers for push under our bundle on its own), so there is no dylib
permission workaround to do — only the mirror + certs + rollout.

## 1. Signing side (this repo — already wired)
- `ios/vk-relay/VKRelay.dylib` is injected for bundles matching
  `storefront.signing.relay_hook.vk_bundle_ids` (default `com.vk.vkclient,com.vk.vkclient1`).
- VK apps get VKRelay.dylib **only** — not MAXRelay/MAXFeatures/MAXGlass (`CompatShim`).
- Build the dylib on a Mac with Xcode: `cd ios/vk-relay && make` (fat arm64+arm64e).
- Rebuild the dylib and commit it whenever `VKRelay.m` changes; the runner ships whatever bytes
  are on disk at signing time (same as MAXRelay.dylib).

## 2. Certs (prod — needs Apple portal + team 1)
VK's storefront bundles differ from MAX's, so they need their own APNs certs:
- new VK (app 102): `com.ruappstore.tge7c5f45f42a430d859e4`
- old VK (app 469): `com.ruappstore.tg38b4a2aa1934b273916c`
Create a push (and, once calls are confirmed, VoIP) cert per bundle on team 1 (recipe:
`max-probe/apple-push-cert.rb`), drop them at:
```
/opt/vkrelay/certs/<bundle>/push-cert.pem
/opt/vkrelay/certs/<bundle>/push-key.pem
```
If the live MAX relay has moved to .p8/JWT token auth, reuse that module instead of the PEM
block in `vk_mirror.py::_apns_push` — the LongPoll loop is unchanged.

## 3. Server mirror (prod)
- Copy `vk_mirror.py` to `/opt/vkrelay/mxrelay.py` (or `vk_mirror.py`).
- Env: `VK_RELAY_SECRET` = same `STOREFRONT_MX_RELAY_SECRET` (reuses the MAX entitlement route),
  `VK_CERTS_DIR=/opt/vkrelay/certs`, `VK_STATE_PATH=/opt/vkrelay/users.json`.
- systemd unit modelled on `mxrelay.service`; `systemctl enable --now vkrelay`.

## 4. nginx `/register` proxy (prod)
The dylib POSTs to `https://www.ruappstore.com/api/v1/vk-relay/register`. Add an **exact-match**
proxy (like MAX's `/api/v1/mx-relay/register`) to the relay's registration writer — it updates
`/opt/vkrelay/users.json`, then the service reloads/restarts. (Not a Laravel route;
only `/api/v1/mx-relay/entitlement` is Laravel, and VK reuses it.)

## 5. Rollout (device)
1. Re-sign + republish new VK (app 102, art 355) so the build carries VKRelay.dylib; then old VK
   (app 469, art 652). Keep all extensions (`--nested`) — stripping PlugIns SIGTRAPs VK on login.
2. Install on Rovshan's device 1, log in, background the app.
3. Confirm in syslog: `[VKRelay] registered (apns=1 ...) -> 200`; relay `users.json` has the row.
4. Send a VK message from another account → APNs alert should appear.
5. Calls (VoIP) still need the live call-event shape confirmed (LongPoll code vs. counters) —
   the VoIP token is captured but the mirror does not yet push calls.
