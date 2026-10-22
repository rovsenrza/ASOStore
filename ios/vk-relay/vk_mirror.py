#!/usr/bin/env python3
# VK (ВКонтакте) notification relay + per-user mirror service — the companion to VKRelay.dylib.
# Same shape as the MAX relay (/opt/mxrelay/mxrelay.py), different wire protocol:
#  - HTTP POST /api/v1/vk-relay/register  (from the injected VKRelay hook): stores a user's VK
#    session token + push tokens + device UDID and (re)starts that user's mirror.
#  - Per user: opens a VK Long-Poll session with the captured app_id 3140623 access token, watches
#    for new incoming messages (LP code 4), and sends our own APNs alert (sender name + text) to
#    that user's device under our per-bundle cert — because the re-signed VK holds no VK push cert,
#    so VK's own background delivery never reaches the device.
# Runs behind nginx (localhost:8801). Push certs live under certs/<bundleId>/.
#
# Validated path (2026-10-17): messages.getLongPollServer(need_pts=1,lp_version=3) -> {server,key,
# ts}; GET https://{server}?act=a_check&key&ts&wait=25&mode=2&version=3 -> {ts,updates:[[code,..]]};
# code 4 = new message [4,msg_id,flags,peer_id,ts,text,{extra},{attachments}] (flags&2 = outgoing).
import os, json, time, threading, subprocess
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

D = os.path.dirname(os.path.abspath(__file__))
STATE = os.path.join(D, "users.json")
PUSH_CERT, PUSH_KEY = os.path.join(D, "push-cert.pem"), os.path.join(D, "push-key.pem")
VOIP_CERT, VOIP_KEY = os.path.join(D, "voip-cert.pem"), os.path.join(D, "voip-key.pem")
CERTS_DIR = os.path.join(D, "certs")  # certs/<bundleId>/{push,voip}-{cert,key}.pem; base fallback

VK_API = "https://api.vk.com"
VK_V = "5.207"
PORT = int(os.environ.get("VKRELAY_PORT", "8801"))
REGISTER_PATH = "/api/v1/vk-relay/register"

# Subscription gating: reuse the MAX entitlement endpoint (device-UDID keyed, not MAX-specific).
ENT_URL = os.environ.get("VKRELAY_ENTITLEMENT_URL", "https://ruappstore.com/api/v1/mx-relay/entitlement")
ENT_SECRET = os.environ.get("VKRELAY_SECRET", "")
ENT_TTL = int(os.environ.get("VKRELAY_ENTITLEMENT_TTL", "600"))
CHECK_INTERVAL = int(os.environ.get("VKRELAY_CHECK_INTERVAL", "600"))
_entitlement_cache = {}  # udid -> (active, checked_at)

_lock = threading.Lock()
_mirrors = {}  # userId -> Mirror


def certs_for(bundle, voip=False):
    base = os.path.join(CERTS_DIR, bundle or "_")
    cert = os.path.join(base, "voip-cert.pem" if voip else "push-cert.pem")
    key = os.path.join(base, "voip-key.pem" if voip else "push-key.pem")
    if os.path.isfile(cert) and os.path.isfile(key):
        return cert, key
    return (VOIP_CERT, VOIP_KEY) if voip else (PUSH_CERT, PUSH_KEY)


def load_state():
    try:
        return json.load(open(STATE))
    except Exception:
        return {}


def save_state(s):
    with _lock:
        tmp = STATE + ".tmp"
        json.dump(s, open(tmp, "w"), ensure_ascii=False)
        os.replace(tmp, STATE)


def check_entitlement(udid):
    if not udid:
        return True  # old/shared build without a per-device UDID: leave ungated.
    now = time.time()
    c = _entitlement_cache.get(udid)
    if c and now - c[1] < ENT_TTL:
        return c[0]
    active = c[0] if c else True  # fail open: a backend blip must not kill notifications.
    try:
        req = Request(ENT_URL, data=json.dumps({"udid": udid}).encode(),
                      headers={"Content-Type": "application/json", "X-MX-Relay-Secret": ENT_SECRET},
                      method="POST")
        with urlopen(req, timeout=10) as r:
            active = bool(json.loads(r.read()).get("active"))
    except Exception as e:
        print(f"[entitle] {udid} check failed: {e}", flush=True)
    _entitlement_cache[udid] = (active, now)
    return active


def vk_get(method, token, **params):
    url = f"{VK_API}/method/{method}?" + urlencode({**params, "access_token": token, "v": VK_V})
    with urlopen(Request(url), timeout=40) as r:
        body = json.loads(r.read().decode("utf-8"))
    if "error" in body:
        e = body["error"]
        raise RuntimeError(f"vk {e.get('error_code')}: {e.get('error_msg')}")
    return body.get("response", {})


class Mirror(threading.Thread):
    def __init__(self, user):
        super().__init__(daemon=True)
        self.user = user
        self.stop = threading.Event()
        self._names = {}  # uid -> (name, avatar)

    def name_avatar(self, uid):
        if uid not in self._names:
            try:
                users = vk_get("users.get", self.user["accessToken"], user_ids=uid, fields="photo_100")
                u = users[0] if users else {}
                nm = f"{u.get('first_name', '')} {u.get('last_name', '')}".strip() or "VK"
                self._names[uid] = (nm, u.get("photo_100"))
            except Exception:
                self._names[uid] = ("VK", None)
        return self._names[uid]

    def push_alert(self, token, bundle, title, text, avatar, peer_id):
        aps = {"alert": {"title": title, "body": text or "📷"}, "sound": "default",
               "mutable-content": 1, "thread-id": f"vk-{peer_id}"}
        data = {"aps": aps, "peer_id": peer_id}
        if avatar:
            data["avatar_url"] = avatar
        pc, pk = certs_for(bundle)
        self._curl(pc, pk, bundle, "alert", token, json.dumps(data, ensure_ascii=False))

    def _curl(self, cert, key, topic, ptype, token, body):
        try:
            r = subprocess.run(["curl", "-s", "--http2", "--cert", cert, "--key", key,
                                "-H", f"apns-topic: {topic}", "-H", f"apns-push-type: {ptype}",
                                "-H", "apns-priority: 10", "-d", body, "-w", "\n%{http_code}",
                                f"https://api.push.apple.com/3/device/{token}"],
                               capture_output=True, text=True, timeout=10)
            out = (r.stdout or "").rsplit("\n", 1)
            status = out[-1].strip() if len(out) == 2 else ""
            payload = out[0] if len(out) == 2 else (r.stdout or "")
            uid = str(self.user.get("userId"))
            if status == "200":
                return
            reason = ""
            try:
                reason = (json.loads(payload) or {}).get("reason", "")
            except Exception:
                pass
            print(f"[push] {uid} {ptype} topic={topic} HTTP {status} {reason}", flush=True)
            # Dead token (uninstalled / invalid): drop it so a fresh re-register repopulates it.
            if status == "410" or reason in ("Unregistered", "BadDeviceToken"):
                try:
                    st = load_state(); u = st.get(uid)
                    if u:
                        field = "voipToken" if ptype == "voip" else "apnsToken"
                        if u.get(field):
                            u[field] = ""; save_state(st)
                        print(f"[push] {uid} pruned {field} ({reason or status})", flush=True)
                except Exception as e:
                    print(f"[push] {uid} prune failed: {e}", flush=True)
        except Exception as e:
            print(f"[push] {self.user.get('userId')} failed: {e}", flush=True)

    def run(self):
        while not self.stop.is_set():
            try:
                self._session()
            except Exception as e:
                print(f"[mirror {self.user.get('userId')}] {e}", flush=True)
            # A failure is usually a rotated access token; the hook re-POSTs a fresh one on the next
            # app launch/foreground, which restarts this mirror. Back off meanwhile.
            self.stop.wait(15)

    def _session(self):
        u = self.user
        token = u["accessToken"]
        bundle = u.get("bundleId", "")
        apns = u.get("apnsToken", "")
        last_check = 0.0
        entitled = True
        lp = vk_get("messages.getLongPollServer", token, need_pts=1, lp_version=3)
        server, key, ts = lp["server"], lp["key"], lp["ts"]
        while not self.stop.is_set():
            url = f"https://{server}?" + urlencode(
                {"act": "a_check", "key": key, "ts": ts, "wait": 25, "mode": 2, "version": 3})
            with urlopen(Request(url), timeout=40) as r:
                res = json.loads(r.read().decode("utf-8"))
            if "failed" in res:
                # 1 = ts outdated (use new ts); 2/3 = key expired -> refetch the LP server.
                if res["failed"] == 1 and "ts" in res:
                    ts = res["ts"]; continue
                return
            ts = res.get("ts", ts)
            now = time.time()
            if now - last_check > ENT_TTL:
                entitled = check_entitlement(u.get("udid")); last_check = now
            if not entitled or not (apns and bundle):
                continue
            for upd in res.get("updates", []):
                if not upd or upd[0] != 4:
                    continue
                flags, peer_id = upd[2], upd[3]
                if flags & 2:  # outgoing — the user sent it
                    continue
                text = upd[5] if len(upd) > 5 else ""
                extra = upd[6] if len(upd) > 6 and isinstance(upd[6], dict) else {}
                if peer_id >= 2_000_000_000:  # group chat: title + real sender from `extra`
                    title = extra.get("title") or "Беседа"
                    sender = int(extra.get("from", 0) or 0)
                    if sender:
                        nm, _ = self.name_avatar(sender)
                        text = f"{nm}: {text}" if text else nm
                    _, avatar = (None, None)
                else:
                    title, avatar = self.name_avatar(peer_id)
                self.push_alert(apns, bundle, title, text, avatar, peer_id)


def start_or_update(user):
    uid = str(user.get("userId") or "")
    if not (uid and user.get("accessToken")):
        return
    if not check_entitlement(user.get("udid")):
        with _lock:
            old = _mirrors.pop(uid, None)
        if old:
            old.stop.set()
        print(f"[relay] user {uid} not entitled; mirror off", flush=True)
        return
    with _lock:
        old = _mirrors.get(uid)
        if old:
            old.stop.set()
        m = Mirror(user); _mirrors[uid] = m; m.start()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_POST(self):
        if self.path.rstrip("/") != REGISTER_PATH:
            self.send_response(404); self.end_headers(); return
        try:
            n = int(self.headers.get("Content-Length", 0))
            user = json.loads(self.rfile.read(n))
        except Exception:
            self.send_response(400); self.end_headers(); return
        uid = str(user.get("userId") or "")
        if not uid:
            self.send_response(422); self.end_headers(); return
        state = load_state(); state[uid] = {**user, "updatedAt": time.time()}; save_state(state)
        start_or_update(state[uid])
        print(f"[relay] registered user {uid} bundle={user.get('bundleId')} "
              f"apns={bool(user.get('apnsToken'))} voip={bool(user.get('voipToken'))}", flush=True)
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers()
        self.wfile.write(b'{"ok":true}')


def supervisor():
    while True:
        time.sleep(CHECK_INTERVAL)
        for uid, user in load_state().items():
            try:
                ok = check_entitlement(user.get("udid")); running = uid in _mirrors
                if ok and not running:
                    start_or_update(user)
                elif not ok and running:
                    with _lock:
                        m = _mirrors.pop(uid, None)
                    if m:
                        m.stop.set()
                    print(f"[relay] user {uid} subscription lapsed; mirror stopped", flush=True)
            except Exception as e:
                print(f"[supervisor] {uid}: {e}", flush=True)


if __name__ == "__main__":
    for uid, user in load_state().items():
        start_or_update(user)
    threading.Thread(target=supervisor, daemon=True).start()
    print(f"[relay] listening on 127.0.0.1:{PORT}, {len(_mirrors)} mirrors restored", flush=True)
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
