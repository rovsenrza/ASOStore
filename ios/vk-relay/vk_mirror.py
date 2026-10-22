#!/usr/bin/env python3
"""VK (ВКонтакте) server-side notification mirror — the companion to VKRelay.dylib.

Same model as the MAX mirror (/opt/mxrelay/mxrelay.py): a re-signed VK build can show a push
only while VK's own servers would, but our re-signed bundle holds no VK push cert, so VK's
background delivery never reaches the device. Instead VKRelay.dylib ships the user's VK session
(access + refresh token, user id) and the device's APNs/VoIP tokens here; this process keeps one
VK Long-Poll session per user and, on each new message the user did not send, pushes an APNs
alert to that device under OUR per-bundle cert.

Validated VK path (2026-10-17, from prod with the app_id 3140623 token):
  GET /method/messages.getLongPollServer?need_pts=1&lp_version=3&access_token=..&v=5.207
      -> {response:{server, key, ts, pts}}
  GET https://{server}?act=a_check&key={key}&ts={ts}&wait=25&mode=2&version=3
      -> {ts, pts, updates:[[code, ...], ...]}; code 4 = new message
         [4, msg_id, flags, peer_id, ts, text, {...}, {attachments...}]
         flags & 2 == outgoing (sent by us) -> skip; peer_id >= 2e9 -> group chat.
  GET /method/users.get?user_ids=..&fields=photo_100,screen_name  -> sender name + avatar.

Entitlement: reuse the MAX endpoint (device-UDID keyed, not MAX-specific):
  POST https://www.ruappstore.com/api/v1/mx-relay/entitlement  {udid}  + X-MX-Relay-Secret
      -> {active, until, reason}

DEPLOY alignment: the APNs client here mirrors the MAX relay's per-bundle PEM cert layout
(/opt/vkrelay/certs/<bundle>/{push,voip}-{cert,key}.pem, base push/voip under /opt/vkrelay). If
the live MAX relay has since moved to JWT/.p8 token auth, reuse that module instead of the cert
block below — the LongPoll loop and payload shaping stay unchanged. See DEPLOY.md.
"""
from __future__ import annotations

import json
import os
import ssl
import threading
import time
from http.client import HTTPSConnection
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, urlopen

VK_API = "https://api.vk.com"
VK_API_VERSION = "5.207"
ENTITLEMENT_URL = os.environ.get(
    "VK_ENTITLEMENT_URL", "https://www.ruappstore.com/api/v1/mx-relay/entitlement"
)
RELAY_SECRET = os.environ.get("VK_RELAY_SECRET", "")
CERTS_DIR = os.environ.get("VK_CERTS_DIR", "/opt/vkrelay/certs")
STATE_PATH = os.environ.get("VK_STATE_PATH", "/opt/vkrelay/users.json")
APNS_HOST = os.environ.get("VK_APNS_HOST", "api.push.apple.com")  # api.sandbox... for dev
ENTITLEMENT_TTL = 300  # seconds to cache an entitlement answer per user

_lock = threading.Lock()


def _vk_get(path: str, params: dict) -> dict:
    """One VK API GET; returns the parsed `response` (or raises on an API error)."""
    params = {**params, "v": VK_API_VERSION}
    url = f"{VK_API}/method/{path}?{urlencode(params)}"
    with urlopen(Request(url), timeout=35) as r:
        body = json.loads(r.read().decode("utf-8"))
    if "error" in body:
        raise RuntimeError(f"vk error {body['error'].get('error_code')}: {body['error'].get('error_msg')}")
    return body.get("response", {})


def _lp_check(server: str, key: str, ts, wait: int = 25) -> dict:
    """One Long-Poll a_check. `server` has no scheme (VK returns api.vk.com/ruim<uid>)."""
    url = f"https://{server}?" + urlencode(
        {"act": "a_check", "key": key, "ts": ts, "wait": wait, "mode": 2, "version": 3}
    )
    with urlopen(Request(url), timeout=wait + 10) as r:
        return json.loads(r.read().decode("utf-8"))


def _entitled(udid: str) -> bool:
    """Ask the backend whether this device's owner still has an active subscription."""
    if not udid or not RELAY_SECRET:
        return True  # fail open (same as the MAX relay) when unconfigured
    try:
        payload = json.dumps({"udid": udid}).encode()
        req = Request(ENTITLEMENT_URL, data=payload, headers={
            "Content-Type": "application/json",
            "X-MX-Relay-Secret": RELAY_SECRET,
        })
        with urlopen(req, timeout=10) as r:
            return bool(json.loads(r.read().decode("utf-8")).get("active"))
    except Exception:
        return True  # never drop a user because the check itself failed


def _apns_push(bundle: str, token: str, title: str, body: str, thread: str) -> int:
    """Send one visible alert via APNs HTTP/2 under the per-bundle cert. Returns the :status."""
    cert = os.path.join(CERTS_DIR, bundle, "push-cert.pem")
    key = os.path.join(CERTS_DIR, bundle, "push-key.pem")
    ctx = ssl.create_default_context()
    ctx.load_cert_chain(certfile=cert, keyfile=key)
    ctx.set_alpn_protocols(["h2"])
    payload = json.dumps({
        "aps": {"alert": {"title": title, "body": body}, "sound": "default",
                "mutable-content": 1, "thread-id": thread},
    }).encode()
    conn = HTTPSConnection(APNS_HOST, 443, context=ctx, timeout=15)
    try:
        conn.request("POST", f"/3/device/{token}", body=payload, headers={
            "apns-topic": bundle, "apns-push-type": "alert", "apns-priority": "10",
        })
        resp = conn.getresponse()
        reason = resp.read().decode("utf-8", "replace")
        if resp.status != 200:
            print(f"[vk-mirror] apns {bundle} {token[:8]} -> {resp.status} {reason}", flush=True)
        return resp.status
    finally:
        conn.close()


class UserSession:
    """One user's Long-Poll loop. Pushes an APNs alert for each incoming (not own) message."""

    def __init__(self, rec: dict):
        self.rec = rec
        self.stop = threading.Event()
        self._names: dict[int, str] = {}

    def _name(self, peer_id: int) -> str:
        if peer_id >= 2_000_000_000:
            return "Беседа"  # group chat; messages.getConversations would give its title
        if peer_id not in self._names:
            try:
                users = _vk_get("users.get", {
                    "user_ids": peer_id, "fields": "screen_name", "access_token": self.rec["accessToken"],
                })
                u = users[0] if users else {}
                self._names[peer_id] = f"{u.get('first_name', '')} {u.get('last_name', '')}".strip() or "VK"
            except Exception:
                self._names[peer_id] = "VK"
        return self._names[peer_id]

    def run(self):
        udid = self.rec.get("udid", "")
        bundle = self.rec.get("bundleId", "")
        token = self.rec.get("apnsToken", "")
        last_entitlement_check = 0.0
        entitled = True
        while not self.stop.is_set():
            try:
                lp = _vk_get("messages.getLongPollServer", {
                    "need_pts": 1, "lp_version": 3, "access_token": self.rec["accessToken"],
                })
                server, key, ts = lp["server"], lp["key"], lp["ts"]
            except Exception as e:
                print(f"[vk-mirror] getLongPollServer failed: {e}", flush=True)
                time.sleep(15)  # token may have rotated; the hook re-POSTs a fresh one on next launch
                continue

            while not self.stop.is_set():
                try:
                    res = _lp_check(server, key, ts)
                except Exception:
                    break  # re-fetch the LP server
                if "failed" in res:
                    break
                ts = res.get("ts", ts)
                now = time.time()
                if now - last_entitlement_check > ENTITLEMENT_TTL:
                    entitled = _entitled(udid)
                    last_entitlement_check = now
                if not entitled:
                    continue
                for upd in res.get("updates", []):
                    if not upd or upd[0] != 4:
                        continue
                    flags, peer_id = upd[2], upd[3]
                    if flags & 2:  # outgoing — the user sent it
                        continue
                    text = upd[5] if len(upd) > 5 else ""
                    if token and bundle:
                        _apns_push(bundle, token, self._name(peer_id), text or "📎", f"vk-{peer_id}")


def load_state() -> dict:
    try:
        with open(STATE_PATH) as f:
            return json.load(f)
    except Exception:
        return {}


def main():
    state = load_state()
    threads: dict[str, UserSession] = {}
    for key, rec in state.items():
        if not rec.get("accessToken"):
            continue
        sess = UserSession(rec)
        threads[key] = sess
        threading.Thread(target=sess.run, name=f"vk-{key}", daemon=True).start()
    print(f"[vk-mirror] started {len(threads)} session(s)", flush=True)
    # The /register endpoint (nginx proxy -> a small writer, or this process extended with an
    # HTTP listener) updates users.json; a production build would reload here on change. For now
    # the service is restarted after a registration batch. Keep the process alive:
    while True:
        time.sleep(3600)


if __name__ == "__main__":
    main()
