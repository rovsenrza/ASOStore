#!/usr/bin/env python3
# MAX notification relay + per-user mirror service.
#  - HTTP POST /register  (from the injected client hook): stores a user's auth frames, tokens
#    and contacts, and (re)starts that user's mirror connection.
#  - Per user: connects to api.oneme.ru:443, replays the captured cmd6/cmd19 login frames,
#    watches for cmd 128 (message) and cmd 137 (call), and sends our own APNs / VoIP pushes
#    (name + text + avatar / native CallKit) to that user's device.
# Runs behind nginx (localhost:8800). Push certs live next to this file.
import os, json, socket, ssl, struct, threading, time, base64, subprocess, binascii
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

D = os.path.dirname(os.path.abspath(__file__))
STATE = os.path.join(D, "users.json")
HOST, PORT = "api.oneme.ru", 443
PUSH_CERT, PUSH_KEY = os.path.join(D, "push-cert.pem"), os.path.join(D, "push-key.pem")
VOIP_CERT, VOIP_KEY = os.path.join(D, "voip-cert.pem"), os.path.join(D, "voip-key.pem")
# Each Apple team signs MAX with its own bundle id, so the APNs/VoIP cert is per-bundle.
# certs/<bundleId>/{push,voip}-{cert,key}.pem; falls back to the top-level base certs (team 1).
CERTS_DIR = os.path.join(D, "certs")
# Presence: our mirror is a background relay session, so it must NOT mark the user online.
# interactive=True in the keepalive is what MAX reads as "foreground/online"; default off.
INTERACTIVE = os.environ.get("MXRELAY_INTERACTIVE", "0") == "1"
import urllib.request, urllib.error
# Subscription gating: the relay asks the backend whether a device still has an active
# subscription (keyed by the per-device UDID the hook sends) and only mirrors/pushes while it
# does. No UDID (old or shared build) = ungated grace. Backend errors fail open (last known).
ENT_URL = os.environ.get("MXRELAY_ENTITLEMENT_URL", "https://ruappstore.com/api/v1/mx-relay/entitlement")
ENT_SECRET = os.environ.get("MXRELAY_SECRET", "")
ENT_TTL = int(os.environ.get("MXRELAY_ENTITLEMENT_TTL", "900"))  # cache a verdict 15 min
# On a 429 (backend throttle) hold the last verdict even longer so we stop hammering the backend.
ENT_429_TTL = int(os.environ.get("MXRELAY_ENTITLEMENT_429_TTL", "1800"))
# Outbound budget: never issue more than this many entitlement calls per minute across ALL users.
# Every check leaves the box on ONE IP, and the backend throttles that IP (telemetry 30/min), so a
# burst of 391 user-checks produced the 45k/day 429s. Staying under budget keeps us off the limit.
ENT_MAX_PER_MIN = int(os.environ.get("MXRELAY_ENTITLEMENT_MAX_PER_MIN", "20"))
CHECK_INTERVAL = int(os.environ.get("MXRELAY_CHECK_INTERVAL", "300"))
# A register whose meaningful fields are unchanged and that arrived within this window is a no-op
# (the client re-registers on every foreground / mute change / and in a retry loop when we are slow).
REG_IDEMPOTENT_WINDOW = int(os.environ.get("MXRELAY_REG_IDEMPOTENT_WINDOW", "300"))
# Debounced disk flush: users.json is ~21 MB; rewriting it on every register (under a global lock)
# was the main thing that made register block past nginx's 15 s read timeout -> 504 -> retry storm.
FLUSH_INTERVAL = float(os.environ.get("MXRELAY_FLUSH_INTERVAL", "5"))
_entitlement_cache = {}  # udid -> (active_bool, checked_at, ttl)
_ent_budget_lock = threading.Lock()
_ent_budget = []  # timestamps of recent outbound entitlement calls (for the per-minute budget)

def certs_for(bundle, voip=False):
    base = os.path.join(CERTS_DIR, bundle or "_")
    cert = os.path.join(base, "voip-cert.pem" if voip else "push-cert.pem")
    key = os.path.join(base, "voip-key.pem" if voip else "push-key.pem")
    if os.path.isfile(cert) and os.path.isfile(key):
        return cert, key
    return (VOIP_CERT, VOIP_KEY) if voip else (PUSH_CERT, PUSH_KEY)

import msgpack
_lock = threading.Lock()
_mirrors = {}  # userId -> Mirror

# App-icon unread badge counter. The re-signed MAX can no longer drive its own icon badge (its
# notification pipeline is broken by the bundle spoof — the badge froze on whatever it last set,
# observed stuck on "1"), so the relay owns it: every pushed message bumps the count and the push
# carries aps.badge so iOS sets the icon number; opening MAX resets it (the foreground register
# sends active=YES). Keyed by APNs token = per physical device (all accounts on one install share
# the token, so the badge is the device's total unread — which is what the icon should show).
_badge = {}  # apnsToken -> unread count
_badge_lock = threading.Lock()

def badge_next(token):
    if not token: return None
    with _badge_lock:
        _badge[token] = _badge.get(token, 0) + 1
        return _badge[token]

def badge_reset(token):
    if not token: return
    with _badge_lock:
        _badge.pop(token, None)

# Persistent APNs HTTP/2 client pool. Previously every push forked a `curl` process, which meant a
# new TLS handshake per notification — slow and CPU-heavy under load, so notifications lagged. httpx
# keeps one HTTP/2 connection per cert open and multiplexes all pushes over it (APNs supports this),
# so a push is now just a request on an already-open socket. Thread-safe: shared across mirrors.
try:
    import httpx
    _HAVE_HTTPX = True
except Exception:
    _HAVE_HTTPX = False

_apns_lock = threading.Lock()
_apns_clients = {}  # (cert,key) -> httpx.Client

def apns_client(cert, key):
    k = (cert, key)
    c = _apns_clients.get(k)
    if c is not None:
        return c
    with _apns_lock:
        c = _apns_clients.get(k)
        if c is None:
            c = httpx.Client(http2=True, cert=(cert, key), timeout=httpx.Timeout(10.0),
                             limits=httpx.Limits(max_keepalive_connections=8, max_connections=16,
                                                 keepalive_expiry=600.0))
            _apns_clients[k] = c
        return c

def drop_apns_client(cert, key):
    with _apns_lock:
        c = _apns_clients.pop((cert, key), None)
    if c is not None:
        try: c.close()
        except Exception: pass

# State is held in memory and flushed to disk by a debounced background thread. Callers mutate the
# dict returned by load_state() under _lock, then call save_state() which only marks it dirty; the
# flusher does the single 21 MB write. This removes the per-register read+write of users.json that
# was serializing all registers behind a global lock and blowing past nginx's proxy_read_timeout.
def _read_disk_state():
    try: return json.load(open(STATE))
    except Exception: return {}

_state = _read_disk_state()
_dirty = threading.Event()

def load_state():
    # Returns the live in-memory state dict. Mutate under _lock, then call save_state().
    return _state

def save_state(s=None):
    # Mark the in-memory state dirty; the flusher persists it. (s is accepted for call-site
    # compatibility; the live _state dict is always what gets written.)
    _dirty.set()

def _flush_state_to_disk():
    with _lock:
        snapshot = json.dumps(_state, ensure_ascii=False)
    tmp = STATE + ".tmp"
    with open(tmp, "w") as f:
        f.write(snapshot)
    os.replace(tmp, STATE)

def flusher():
    while True:
        _dirty.wait()
        time.sleep(FLUSH_INTERVAL)  # coalesce a burst of registers into one write
        _dirty.clear()
        try:
            _flush_state_to_disk()
        except Exception as e:
            print(f"[flusher] write failed: {e}", flush=True)
            _dirty.set()  # retry next cycle

def _ent_budget_ok():
    # Token-bucket: allow at most ENT_MAX_PER_MIN outbound entitlement calls per rolling minute.
    now = time.time()
    with _ent_budget_lock:
        while _ent_budget and now - _ent_budget[0] > 60:
            _ent_budget.pop(0)
        if len(_ent_budget) >= ENT_MAX_PER_MIN:
            return False
        _ent_budget.append(now)
        return True

def check_entitlement(udid):
    if not udid:
        return True  # old/shared build without a per-device UDID: leave ungated.
    now = time.time()
    c = _entitlement_cache.get(udid)
    if c and now - c[1] < c[2]:
        return c[0]
    active = c[0] if c else True  # fail open: a backend blip must not kill notifications.
    # Over budget: do not call the backend (that is what tripped its throttle). Serve the last known
    # verdict; if we have none yet, fail open. Re-cache briefly so we retry soon, not every request.
    if not _ent_budget_ok():
        _entitlement_cache[udid] = (active, now, 30)
        return active


# Bulk refresh: one backend call answers every UDID the relay holds. One-at-a-time checks under
# ENT_MAX_PER_MIN starved: after a restart most users were never asked and kept the fail-open
# default, so lapsed customers got notifications (and calls) for days. A verdict from the bulk call
# lives until the next pass plus slack, so the per-user path is only used for brand-new registers.
ENT_BULK_URL = ENT_URL.rstrip("/") + "s"  # .../mx-relay/entitlement -> .../mx-relay/entitlements
ENT_BULK_TTL = max(ENT_TTL, CHECK_INTERVAL * 3)

def refresh_entitlements(udids):
    import urllib.request as _ur
    udids = sorted({u for u in udids if u})
    if not udids:
        return True
    try:
        req = _ur.Request(ENT_BULK_URL, data=json.dumps({"udids": udids}).encode(),
            headers={"Content-Type": "application/json", "Accept": "application/json", "X-MX-Relay-Secret": ENT_SECRET}, method="POST")
        with _ur.urlopen(req, timeout=30) as r:
            results = json.loads(r.read()).get("results") or {}
    except Exception as e:
        # Keep the last known verdicts (fail open only for users never seen), retry next pass.
        print(f"[entitle] bulk refresh failed: {e}", flush=True)
        return False
    now = time.time(); denied = 0
    for u in udids:
        v = results.get(u)
        if v is None:
            continue
        ok = bool(v.get("active")); denied += (not ok)
        _entitlement_cache[u] = (ok, now, ENT_BULK_TTL)
    print(f"[entitle] bulk refresh: {len(udids)} udids, {denied} not entitled", flush=True)
    return True
    ttl = ENT_TTL
    try:
        req = urllib.request.Request(ENT_URL, data=json.dumps({"udid": udid}).encode(),
            headers={"Content-Type": "application/json", "X-MX-Relay-Secret": ENT_SECRET}, method="POST")
        with urllib.request.urlopen(req, timeout=10) as r:
            active = bool(json.loads(r.read()).get("active"))
    except urllib.error.HTTPError as e:
        if e.code == 429:
            # Backend throttled us: hold the last verdict for a long time to stop the stampede.
            ttl = ENT_429_TTL
        else:
            print(f"[entitle] {udid} check failed: {e}", flush=True)
    except Exception as e:
        print(f"[entitle] {udid} check failed: {e}", flush=True)
    _entitlement_cache[udid] = (active, now, ttl)
    return active

# ---- MessagePack field extraction (same as the prototype) ----
def _mp_str(b, p):
    if p >= len(b): return None
    c = b[p]
    if 0xa0 <= c <= 0xbf: n, p = c & 0x1f, p + 1
    elif c == 0xd9: n, p = b[p+1], p+2
    elif c == 0xda: n, p = int.from_bytes(b[p+1:p+3], "big"), p+3
    else: return None
    return b[p:p+n].decode("utf-8", "replace")

def _mp_int(b, p):
    if p >= len(b): return None
    c = b[p]
    if c < 0x80: return c
    s = {0xcc:1,0xcd:2,0xce:4,0xcf:8,0xd0:1,0xd1:2,0xd2:4,0xd3:8}
    if c in s: n = s[c]; return int.from_bytes(b[p+1:p+1+n], "big", signed=c >= 0xd0)
    return None

def field_str(b, k):
    i = b.find(bytes([0xa0|len(k)]) + k.encode()); return _mp_str(b, i+1+len(k)) if i >= 0 else None

def field_int(b, k):
    i = b.find(bytes([0xa0|len(k)]) + k.encode()); return _mp_int(b, i+1+len(k)) if i >= 0 else None

# A message with no text carries an attachment; its _type (uppercase in the frame) gives the
# notification label. Order matters: the first marker found wins.
ATTACH_LABELS = [
    (b"STICKER", "Стикер"),
    (b"PHOTO", "Фотография"),
    (b"IMAGE", "Фотография"),
    (b"VIDEO", "Видео"),
    (b"VOICE", "Голосовое сообщение"),
    (b"AUDIO", "Голосовое сообщение"),
    (b"MUSIC", "Аудиозапись"),
    (b"GIF", "GIF"),
    (b"FILE", "Файл"),
    (b"CONTACT", "Контакт"),
    (b"LOCATION", "Геопозиция"),
    (b"POLL", "Опрос"),
]

def attach_label(body):
    for marker, label in ATTACH_LABELS:
        if marker in body:
            return label
    return None

# MAX compresses longer message text with a (dictionary-based) back-reference scheme using 0xf2
# tokens, which field_str reads as raw bytes → U+FFFD + control bytes mid-string. Not reversible
# server-side, so show the clean leading run up to the first compression byte, then an ellipsis.
def clean_preview(t):
    if not t:
        return t
    cut = len(t)
    for idx, ch in enumerate(t):
        if ch == "�" or (ord(ch) < 0x20 and ch not in "\n\t"):
            cut = idx
            break
    s = t[:cut].strip()
    if cut < len(t):
        s = (s + " …").strip() if s else "Сообщение"
    return s


# Global, cross-session call-push dedup keyed by the DEVICE (voip token). One physical device
# may be registered under several accounts (the user logged into more than one over time); they
# share one voip token, so without this every such account's mirror would push the same incoming
# call to the device at once -> MAX reports multiple incoming calls and fails them (CXCallEndedReason
# Failed). A device handles one call at a time, so we let only the first push per token win briefly.
_GLOBAL_CALL_LOCK = threading.Lock()
_GLOBAL_CALL_PUSHES = {}  # voipToken -> ts of last call push to that device

def claim_call_push(voip_token, window=20.0):
    if not voip_token:
        return True
    now = time.time()
    with _GLOBAL_CALL_LOCK:
        for t, ts in list(_GLOBAL_CALL_PUSHES.items()):
            if now - ts > 120:
                _GLOBAL_CALL_PUSHES.pop(t, None)
        last = _GLOBAL_CALL_PUSHES.get(voip_token)
        if last is not None and now - last < window:
            return False
        _GLOBAL_CALL_PUSHES[voip_token] = now
        return True


def lz4_block_decompress(src):
    """Raw LZ4 block decoder (no frame header, no preset dict). MAX compresses the whole packet body
    with plain LZ4 block when the header compression flag (byte[6]) is set; the relay must decompress
    it before field extraction, otherwise longer messages read as garbage and get truncated. stdlib only."""
    out = bytearray(); i = 0; n = len(src)
    while i < n:
        token = src[i]; i += 1
        litlen = token >> 4
        if litlen == 15:
            while i < n:
                b = src[i]; i += 1; litlen += b
                if b != 255: break
        out += src[i:i + litlen]; i += litlen
        if i >= n:
            break  # final sequence: literals only
        offset = src[i] | (src[i + 1] << 8); i += 2
        if offset == 0:
            raise ValueError("invalid LZ4 offset 0")
        mlen = (token & 0xf) + 4
        if (token & 0xf) == 15:
            while i < n:
                b = src[i]; i += 1; mlen += b
                if b != 255: break
        start = len(out) - offset
        if start < 0:
            raise ValueError("backref before start")
        for k in range(mlen):
            out.append(out[start + k])
    return bytes(out)


class Mirror(threading.Thread):
    def __init__(self, user):
        super().__init__(daemon=True)
        self.user = user
        self.stop = threading.Event()
        self.recent_calls = {}  # conversationId -> ts, to send only one VoIP push per call
        self._cur_sock = None   # the live session socket, closed by run() when the session ends
        self._cur_dead = None   # per-session Event; set on session end so keepalive exits deterministically

    def contacts_name_avatar(self, uid):
        for c in self.user.get("contacts", []):
            if str(c.get("id")) == str(uid):
                return c.get("name"), c.get("avatar")
        return None, None

    def push_alert(self, token, bundle, name, text, avatar, sender_id, conv, chat_id=None, msgid=None):
        # NO mutable-content: with it, MAX/diyorde's Notification Service Extension runs and rebuilds
        # the notification (for the avatar), dropping our sound -> the banner arrives SILENTLY when the
        # screen is off (device-observed). We send plaintext title/body in aps.alert, so iOS shows them
        # directly without the NSE and plays sound:default even locked. Avatar is deprioritised.
        # category + top-level mc(=chatId) + msgid match MAX's own push format so tapping the banner
        # opens that dialog (MAX reads aps.category, then userInfo["mc"] as chat id via -longLongValue).
        aps = {"alert": {"title": name, "body": text}, "sound": "default"}
        # app-icon unread badge: absolute count, applied by iOS springboard directly (no NSE needed).
        # Only for devices whose dylib is badge-capable (it resets the count on open + clears the icon
        # correctly). Old-dylib devices never send the `active` key, so we skip the badge for them —
        # otherwise their count would climb forever with nothing to clear it. See badge_ok below.
        if self.user.get("badge_ok"):
            n = badge_next(token)
            if n is not None:
                aps["badge"] = n
        if chat_id is not None:
            # category must match the chat type for MAX to route the tap: 1:1 dialogs have a
            # positive chatId ("Message"), group chats a negative one ("ChatMessage").
            aps["category"] = "Message" if int(chat_id) > 0 else "ChatMessage"
            # thread-id groups a chat's notifications together on the lock screen / notification centre
            # (native MAX uses threadIdentifier per chat); without it every message is its own banner.
            aps["thread-id"] = str(chat_id)
        data = {"aps": aps, "sender": name, "text": text,
                "sender_id": str(sender_id), "conv": str(conv)}
        if chat_id is not None: data["mc"] = int(chat_id)
        if msgid is not None: data["msgid"] = int(msgid)
        if avatar: data["avatar_url"] = avatar
        pc, pk = certs_for(bundle)
        self._send(pc, pk, bundle, "alert", token, json.dumps(data, ensure_ascii=False))

    def push_voip(self, token, bundle, payload):
        vc, vk = certs_for(bundle, voip=True)
        self._send(vc, vk, bundle + ".voip", "voip", token, json.dumps(payload, ensure_ascii=False))

    def _send(self, cert, key, topic, ptype, token, body):
        uid = str(self.user.get("userId"))
        try:
            if _HAVE_HTTPX:
                try:
                    r = apns_client(cert, key).post(
                        f"https://api.push.apple.com/3/device/{token}",
                        headers={"apns-topic": topic, "apns-push-type": ptype, "apns-priority": "10"},
                        content=body.encode("utf-8"))
                    status, payload = str(r.status_code), r.text
                except Exception as e:
                    # Connection went away (APNs GOAWAY / idle close): drop the pooled client so the
                    # next push reconnects, and fall back to curl for this one so it is not lost.
                    drop_apns_client(cert, key)
                    print(f"[push] {uid} httpx error, curl fallback: {e}", flush=True)
                    status, payload = self._curl(cert, key, topic, ptype, token, body)
            else:
                status, payload = self._curl(cert, key, topic, ptype, token, body)
            if status == "200":
                return
            reason = ""
            try: reason = (json.loads(payload) or {}).get("reason", "")
            except Exception: pass
            print(f"[push] {uid} {ptype} topic={topic} HTTP {status} {reason}", flush=True)
            if reason == "BadDeviceToken":
                print(f"[push] {uid} DEBUGTOKEN {ptype} {token}", flush=True)  # TEMP diag
            # Dead token (device uninstalled / token invalid): drop it so it stops retrying and a
            # fresh re-register from the hook repopulates it. Topic mismatch is a stale registration
            # (wrong bundleId from an old build) -> only a reinstall+re-register fixes it; just logged.
            if status == "410" or reason in ("Unregistered", "BadDeviceToken"):
                try:
                    st = load_state(); u = st.get(uid)
                    if u:
                        field = "voipToken" if ptype == "voip" else "apnsToken"
                        if u.get(field): u[field] = ""; save_state(st)
                        print(f"[push] {uid} pruned {field} ({reason or status})", flush=True)
                except Exception as e:
                    print(f"[push] {uid} prune failed: {e}", flush=True)
        except Exception as e:
            print(f"[push] {self.user.get('userId')} failed: {e}", flush=True)

    def _curl(self, cert, key, topic, ptype, token, body):
        # Fallback only (httpx unavailable, or a transient httpx connection error). Returns
        # (status, payload); parsing + dead-token pruning stay in _send.
        try:
            r = subprocess.run(["curl", "-s", "--http2", "--cert", cert, "--key", key,
                            "-H", f"apns-topic: {topic}", "-H", f"apns-push-type: {ptype}", "-H", "apns-priority: 10",
                            "-d", body, "-w", "\n%{http_code}", f"https://api.push.apple.com/3/device/{token}"],
                           capture_output=True, text=True, timeout=10)
            out = (r.stdout or "").rsplit("\n", 1)
            status = out[-1].strip() if len(out) == 2 else ""
            payload = out[0] if len(out) == 2 else (r.stdout or "")
            return status, payload
        except Exception as e:
            print(f"[push] {self.user.get('userId')} curl failed: {e}", flush=True)
            return "", ""

    def run(self):
        while not self.stop.is_set():
            try: self._session()
            except Exception as e: print(f"[mirror {self.user.get('userId')}] {e}", flush=True)
            finally:
                # End-of-session cleanup: signal the keepalive thread to exit and close the socket
                # NOW instead of waiting for keepalive's next sendall to error. A TSPU-frozen socket
                # does not raise promptly, so without this the keepalive thread (and the fd it holds)
                # leaked once per reconnect -> thousands of threads/sockets over days.
                d = self._cur_dead
                if d is not None: d.set()
                sk = self._cur_sock
                if sk is not None:
                    try: sk.close()
                    except Exception: pass
                self._cur_sock = None; self._cur_dead = None
            # Reconnect fast: every second disconnected is a second of missed inbound messages.
            self.stop.wait(2)

    def _session(self):
        u = self.user
        own = u.get("userId"); apns = u.get("apnsToken"); voip = u.get("voipToken"); bundle = u.get("bundleId")
        f6 = base64.b64decode(u["frame6"]); f19 = base64.b64decode(u["frame19"])
        ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
        # RU ISP / TSPU can silently freeze this socket while it still reads as "connected"; without
        # TCP keepalive the only detector is the 120 s recv timeout below, so up to ~2 min of incoming
        # messages are lost before we reconnect (= "notifications sometimes don't arrive"). Enable OS
        # keepalive so a dead peer is detected in ~30+3*10 = ~60 s instead, roughly halving that window.
        raw = socket.create_connection((HOST, PORT), timeout=20)
        raw.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        for opt, val in (("TCP_KEEPIDLE", 30), ("TCP_KEEPINTVL", 10), ("TCP_KEEPCNT", 3)):
            try: raw.setsockopt(socket.IPPROTO_TCP, getattr(socket, opt), val)
            except (AttributeError, OSError): pass  # option unsupported on this platform
        s = ctx.wrap_socket(raw, server_hostname=HOST)
        sess_dead = threading.Event()
        self._cur_sock = s; self._cur_dead = sess_dead
        s.sendall(f6); s.sendall(f19)
        print(f"[mirror {own}] connected + auth replayed", flush=True)

        def keepalive():
            seq = 50000
            # Wait on this session's own Event: it returns at once when run() marks the session dead,
            # so the keepalive thread cannot outlive its socket (previously it only exited when a
            # sendall raised, which a frozen socket never does -> the thread/fd leak).
            while not sess_dead.wait(20):
                if self.stop.is_set(): break
                try:
                    body = msgpack.packb({"interactive": INTERACTIVE}, use_bin_type=True)
                    hdr = bytes([0x0a, 0x00]) + struct.pack(">H", seq) + struct.pack(">H", 1) + b"\x00" + struct.pack(">I", len(body))[1:]
                    s.sendall(hdr + body); seq += 1
                except Exception: break
        threading.Thread(target=keepalive, daemon=True).start()

        s.settimeout(120)
        while not self.stop.is_set():
            hdr = self._recvn(s, 10)
            opcode = struct.unpack(">H", hdr[4:6])[0]
            cof = hdr[6]  # OKMPPacket compression flag; set => body is LZ4-block compressed (whole frame)
            length = struct.unpack(">I", b"\x00" + hdr[7:10])[0]
            body = self._recvn(s, length) if length else b""
            if cof and body:
                # Decompress + validate; on any mismatch keep the raw body (safe for a mis-set flag).
                try:
                    dec = lz4_block_decompress(body)
                    msgpack.unpackb(dec, raw=True, strict_map_key=False)
                    body = dec
                except Exception:
                    pass
            if opcode == 128:
                sender = field_int(body, "sender"); text = field_str(body, "text")
                chat_id = field_int(body, "chatId"); msgid = field_int(body, "id")
                muted = {str(x) for x in (self.user.get("muted") or [])}
                if sender and sender != own and str(chat_id) not in muted and str(sender) not in muted:
                    # Text message, or (when empty) a sticker / media attachment.
                    label = clean_preview(text) if text else attach_label(body)
                    if label:
                        nm, av = self.contacts_name_avatar(sender)
                        self.push_alert(apns, bundle, nm or f"Пользователь {sender}", label, av, sender, sender, chat_id, msgid)
                        # Do NOT log sender name or message text: that persists personal content to the
                        # systemd journal in plaintext. chat id + length only is enough for debugging.
                        print(f"[mirror {own}] msg (chat {chat_id}) len={len(label)} -> push", flush=True)
            elif opcode == 137:
                caller = field_int(body, "callerId"); conv = field_str(body, "conversationId"); ctype = field_str(body, "type") or "AUDIO"
                # MAX's own VoIP handler (-[OKMPushServiceRegister _processVoIPPushPayload:…], RE'd
                # from the signed binary) reads the push dict with THESE keys and REQUIRES vcp, suid
                # and vcId all non-empty — otherwise it logs a nonFatal and falls back to
                # -fakeIncomingCallWithCallerId:callerName: → the "unknown" 1-second call. It also
                # reads chatId, iv (isVideo bool), and the caller name from aps.alert.body (→ userName
                # fallback); when data-protected (locked cold) it can only use aps.alert.body + suid +
                # iv straight from the payload. So the push MUST mirror that shape exactly.
                # The call frame itself carries MAX's real `vcp` call token (a long base64/JWT-like
                # join credential, NOT the conversationId) — pass it through verbatim; MAX needs it
                # to actually join the call. Fall back to callId/conv only if truly absent.
                vcp_tok = field_str(body, "vcp")
                if vcp_tok is None:
                    call_id = field_str(body, "callId")
                    if call_id is None:
                        ci = field_int(body, "callId");  call_id = str(ci) if ci is not None else None
                    vcp_tok = call_id
                chat_id_c = field_int(body, "chatId")
                # One-time per session: dump the real call-frame field names so the exact callId/
                # conversationId mapping can be verified against this RE.
                if not getattr(self, "_call_dumped", False):
                    self._call_dumped = True
                    try:
                        keys = list(msgpack.unpackb(body, raw=True, strict_map_key=False).keys())
                        print(f"[mirror {own}] call frame keys={keys}", flush=True)
                    except Exception:
                        print(f"[mirror {own}] call frame raw={body[:160].hex()}", flush=True)
                now = time.time()
                self.recent_calls = {k: v for k, v in self.recent_calls.items() if now - v < 60}
                if caller and conv and conv not in self.recent_calls:
                    self.recent_calls[conv] = now
                    nm, _ = self.contacts_name_avatar(caller)
                    name = nm or f"{caller}"
                    is_video = ctype == "VIDEO"
                    vcp = vcp_tok if vcp_tok else str(conv)   # MAX requires vcp non-empty
                    payload = {
                        "aps": {"alert": {"body": name}},     # locked-cold caller name
                        "vcp": vcp,                            # call token (required, join credential)
                        "suid": str(caller),                   # caller user id (required)
                        "vcId": str(conv),                     # conversation id (required)
                        "iv": is_video,                        # isVideo
                        "userName": name,                      # fallback caller name
                        "isContact": bool(nm),
                    }
                    # chatId 0 / absent == 1:1 dialog; only forward a real (non-zero) chat id, else
                    # MAX routes to the chat handler instead of the dialog handler.
                    if chat_id_c:
                        payload["chatId"] = str(chat_id_c)
                    # Only one call push per physical device (voip token): other accounts sharing
                    # this device must not double-report the call to CallKit.
                    if not claim_call_push(voip):
                        print(f"[mirror {own}] call -> SKIP (device already got a call push)", flush=True)
                    else:
                        self.push_voip(voip, bundle, payload)
                        # No caller name and no vcp (a join credential) in the log.
                        print(f"[mirror {own}] call vcId={conv} iv={is_video} -> voip push", flush=True)

    def _recvn(self, s, n):
        buf = b""
        while len(buf) < n:
            c = s.recv(n - len(buf))
            if not c: raise ConnectionError("closed")
            buf += c
        return buf


def _mirror_sig(u):
    # The fields that require a reconnect/re-auth when they change. Contacts and muted lists do NOT:
    # the running mirror reads them from self.user at push time, so we refresh them in place.
    return (u.get("frame6"), u.get("frame19"), u.get("apnsToken"),
            u.get("voipToken"), u.get("bundleId"), u.get("udid"))

def start_or_update(user, force=False):
    uid = str(user.get("userId"))
    if not (user.get("userId") and user.get("frame19") and user.get("frame6")): return
    if not check_entitlement(user.get("udid")):
        with _lock:
            old = _mirrors.pop(uid, None)
        if old: old.stop.set()
        print(f"[relay] user {uid} not entitled (no active subscription); mirror off", flush=True)
        return
    with _lock:
        old = _mirrors.get(uid)
        if old and old.is_alive() and not force and _mirror_sig(old.user) == _mirror_sig(user):
            old.user = user  # refresh contacts/muted without tearing down the live connection
            return
        if old: old.stop.set()
        m = Mirror(user); _mirrors[uid] = m; m.start()


# ---- Asynchronous register pipeline ----------------------------------------------------------
# The HTTP handler must return within nginx's proxy_read_timeout (15 s) no matter what. Entitlement
# checks, the 21 MB state flush and mirror (re)connection all used to happen inline, so a burst made
# register exceed the timeout -> 504 -> the client retries -> worse burst. Now the handler only
# validates + enqueues and replies 200 immediately; a small worker pool does the real work, and
# unchanged re-registrations are dropped as no-ops.
import queue, hashlib
_reg_q = queue.Queue(maxsize=4000)
_last_reg = {}  # uid -> (sig, ts)

def _reg_sig(user):
    keys = ("frame6", "frame19", "apnsToken", "voipToken", "bundleId", "udid", "contacts", "muted", "active")
    blob = json.dumps({k: user.get(k) for k in keys}, sort_keys=True, ensure_ascii=False)
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()

def _process_register(uid, user):
    # A foreground (didBecomeActive) register means the user opened MAX and is reading -> clear this
    # device's unread badge counter so the next push starts from 1 again. Done before the idempotency
    # check (and 'active' is in _reg_sig, so an open is never dropped as a duplicate register).
    if user.get("active"):
        badge_reset(user.get("apnsToken"))
    sig = _reg_sig(user); now = time.time(); prev = _last_reg.get(uid)
    with _lock:
        m = _mirrors.get(uid); alive = bool(m and m.is_alive())
    if prev and prev[0] == sig and now - prev[1] < REG_IDEMPOTENT_WINDOW and alive:
        return  # identical payload, mirror healthy -> nothing to do
    _last_reg[uid] = (sig, now)
    with _lock:
        # Sticky flag: a register carrying the `active` key proves this device runs the badge-capable
        # dylib, so the relay may drive its icon badge. Never unset (old-dylib re-registers lack it).
        badge_ok = _state.get(uid, {}).get("badge_ok") or ("active" in user)
        _state[uid] = {**user, "updatedAt": now}
        if badge_ok:
            _state[uid]["badge_ok"] = True
        stored = _state[uid]
    save_state()
    start_or_update(stored)
    print(f"[relay] registered user {uid} bundle={user.get('bundleId')} apns={bool(user.get('apnsToken'))} voip={bool(user.get('voipToken'))} ({len(user.get('contacts', []))} contacts, frames={bool(user.get('frame19'))})", flush=True)

def reg_worker():
    while True:
        uid, user = _reg_q.get()
        try: _process_register(uid, user)
        except Exception as e: print(f"[register] {uid}: {e}", flush=True)
        finally: _reg_q.task_done()


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def log_message(self, *a): pass
    def _reply(self, code, body=b""):
        self.send_response(code)
        if body: self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if body: self.wfile.write(body)
    def do_POST(self):
        if self.path.rstrip("/") != "/api/v1/mx-relay/register":
            self._reply(404); return
        try:
            n = int(self.headers.get("Content-Length", 0))
            user = json.loads(self.rfile.read(n))
        except Exception:
            self._reply(400); return
        uid = str(user.get("userId") or "")
        if not uid:
            self._reply(422); return
        try:
            _reg_q.put_nowait((uid, user))
        except queue.Full:
            # Backpressure: drop this one. The client re-registers and the work is idempotent, so no
            # state is lost; we just protect the box from an unbounded backlog.
            print(f"[relay] register queue full, dropped {uid}", flush=True)
        self._reply(200, b'{"ok":true}')


def supervisor():
    # Periodically recheck subscriptions: stop a lapsed user's mirror, restart a resubscribed one.
    while True:
        time.sleep(CHECK_INTERVAL)
        _users = load_state()
        refresh_entitlements(u.get("udid") for u in _users.values())
        for uid, user in list(_users.items()):
            try:
                ok = check_entitlement(user.get("udid")); running = uid in _mirrors
                if ok and not running:
                    start_or_update(user)
                elif not ok and running:
                    with _lock:
                        m = _mirrors.pop(uid, None)
                    if m: m.stop.set()
                    print(f"[relay] user {uid} subscription lapsed; mirror stopped", flush=True)
            except Exception as e:
                print(f"[supervisor] {uid}: {e}", flush=True)

if __name__ == "__main__":
    threading.Thread(target=flusher, daemon=True).start()
    for _ in range(int(os.environ.get("MXRELAY_REG_WORKERS", "4"))):
        threading.Thread(target=reg_worker, daemon=True).start()
    refresh_entitlements(u.get("udid") for u in load_state().values())
    for uid, user in list(load_state().items()):
        start_or_update(user)
    threading.Thread(target=supervisor, daemon=True).start()
    print(f"[relay] listening on 127.0.0.1:8800, {len(_mirrors)} mirrors restored", flush=True)
    ThreadingHTTPServer.request_queue_size = 256  # absorb bursts instead of dropping at backlog 5
    ThreadingHTTPServer.daemon_threads = True
    ThreadingHTTPServer(("127.0.0.1", 8800), Handler).serve_forever()
