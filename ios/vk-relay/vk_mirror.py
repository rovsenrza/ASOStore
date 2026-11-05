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
from urllib.parse import urlencode, urlparse, parse_qs
from urllib.request import Request, urlopen
from urllib.error import HTTPError
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
# APNs VoIP payloads cap at 5120 bytes; stay under it with headroom so the push is never rejected.
VOIP_MAX_BYTES = 4800

def _backoff_wait(exc, base):
    """Long back-off for accounts whose token is dead (expired / invalid_grant / 1117): polling them
    every few seconds floods VK and can rate-limit the relay IP, starving healthy accounts. They
    restart with a fresh token when the app next registers, so backing off is safe."""
    m = str(exc).lower()
    if "expired" in m or "invalid_grant" in m or "1117" in m or "access token" in m:
        return 300.0
    return base


# Subscription gating: reuse the MAX entitlement endpoint (device-UDID keyed, not MAX-specific).
ENT_URL = os.environ.get("VKRELAY_ENTITLEMENT_URL", "https://ruappstore.com/api/v1/mx-relay/entitlement")
ENT_SECRET = os.environ.get("VKRELAY_SECRET", "")
ENT_TTL = int(os.environ.get("VKRELAY_ENTITLEMENT_TTL", "600"))
# Same protection as the MAX relay: if the backend throttles us (429), hold the last verdict for a
# long time, and never issue more than ENT_MAX_PER_MIN entitlement calls per minute across ALL users.
ENT_429_TTL = int(os.environ.get("VKRELAY_ENTITLEMENT_429_TTL", "1800"))
ENT_MAX_PER_MIN = int(os.environ.get("VKRELAY_ENTITLEMENT_MAX_PER_MIN", "20"))
_ent_budget = []
_ent_budget_lock = threading.Lock()
CHECK_INTERVAL = int(os.environ.get("VKRELAY_CHECK_INTERVAL", "600"))
_entitlement_cache = {}  # udid -> (active, checked_at)

_lock = threading.Lock()
_register_lock = threading.Lock()
_mirrors = {}  # userId -> Mirror


def certs_for(bundle, voip=False):
    base = os.path.join(CERTS_DIR, bundle or "_")
    cert = os.path.join(base, "voip-cert.pem" if voip else "push-cert.pem")
    key = os.path.join(base, "voip-key.pem" if voip else "push-key.pem")
    if os.path.isfile(cert) and os.path.isfile(key):
        return cert, key
    return (VOIP_CERT, VOIP_KEY) if voip else (PUSH_CERT, PUSH_KEY)


def norm_uid(v):
    # VK wraps the id as {"value": N}; older hook builds posted that dict verbatim. Coerce to a
    # plain string key so one user never maps to two entries across hook versions.
    if isinstance(v, dict):
        v = v.get("value")
    return str(v) if v is not None else ""


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


def _ent_budget_ok():
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
    # Over budget: do not call the backend; serve the last verdict and retry soon.
    if not _ent_budget_ok():
        _entitlement_cache[udid] = (active, now, 30)
        return active
    ttl = ENT_TTL
    try:
        req = Request(ENT_URL, data=json.dumps({"udid": udid}).encode(),
                      headers={"Content-Type": "application/json", "X-MX-Relay-Secret": ENT_SECRET},
                      method="POST")
        with urlopen(req, timeout=10) as r:
            res = json.loads(r.read())
        active = bool(res.get("active"))
        if not active:
            print(f"[entitle] {udid} not entitled ({res.get('reason')})", flush=True)
    except HTTPError as e:
        if e.code == 429:
            ttl = ENT_429_TTL  # backend throttled us: hold the last verdict, stop the stampede
        print(f"[entitle] {udid} check failed: {e}", flush=True)
    except Exception as e:
        print(f"[entitle] {udid} check failed: {e}", flush=True)
    _entitlement_cache[udid] = (active, now, ttl)
    return active


# --- VKID token refresh -------------------------------------------------------------------------
# The captured access token (app_id 3140623) expires ~1 day; VK re-mints it only when the user
# reopens the app. The hook also captures the VKID refreshToken + the device_id candidates it can
# read on our build, so we refresh server-side via VKID OAuth2.1 and keep the mirror alive without
# the user touching the app. VKID binds the refresh token to the EXACT device_id used at auth, so we
# try each captured candidate and remember the one that works (user["deviceId"]).
VKID_AUTH_URL = "https://id.vk.com/oauth2/auth"
VKID_CLIENT_ID = os.environ.get("VKRELAY_VKID_CLIENT_ID", "3140623")
# Per-user refresh backoff: VKID answers slow_down under load; three pollers (calls/mirror/notif)
# each hit an expired token every loop, so without this they flood id.vk.com and the rate limit
# never clears. After a failed refresh we refuse further id.vk.com calls for this user until then.
_refresh_next = {}
_REFRESH_COOLDOWN = 90    # ordinary failed refresh (e.g. invalid_grant)
_REFRESH_SLOWDOWN = 600   # VKID returned slow_down (rate limited)
import uuid as _uuid  # noqa: E402
CF_EPOCH = 978307200  # CFAbsoluteTime (2001-01-01) -> Unix epoch offset; accessTokenExpiry is CF time.


def token_expired(user, skew=120):
    exp = user.get("accessTokenExpiry")
    try:
        return exp is not None and (CF_EPOCH + float(exp)) <= time.time() + skew
    except (TypeError, ValueError):
        return False


def _device_candidates(user):
    out = []
    for d in [user.get("deviceId")] + list(user.get("deviceIds", []) or []):
        if isinstance(d, str):
            d = d.strip().strip('"').strip()
        if d and d not in out:
            out.append(d)
    return out


def vkid_refresh(user):
    """Exchange the stored refreshToken for a fresh access token. Updates persisted state + the live
    user dict in place; returns the new access token, or None if no candidate device_id works."""
    uid = norm_uid(user.get("userId"))
    if time.time() < _refresh_next.get(uid, 0):
        return None
    rt = user.get("refreshToken")
    cands = _device_candidates(user)
    if not (rt and cands):
        return None
    saw_slowdown = False
    for dev in cands:
        try:
            data = urlencode({"grant_type": "refresh_token", "refresh_token": rt,
                              "client_id": VKID_CLIENT_ID, "device_id": dev,
                              "state": _uuid.uuid4().hex, "scope": ""}).encode()
            req = Request(VKID_AUTH_URL, data=data,
                          headers={"Content-Type": "application/x-www-form-urlencoded"})
            with urlopen(req, timeout=20) as r:
                res = json.loads(r.read().decode("utf-8"))
        except Exception as e:
            print(f"[refresh] {uid} dev={dev[:8]} HTTP err {e}", flush=True)
            continue
        at = res.get("access_token")
        if at:
            with _lock:
                try:
                    st = json.load(open(STATE))
                except Exception:
                    st = {}
                u = st.get(uid, {})
                u["accessToken"] = at
                if res.get("refresh_token"):
                    u["refreshToken"] = res["refresh_token"]
                u["deviceId"] = dev
                if res.get("expires_in"):
                    u["accessTokenExpiry"] = (time.time() - CF_EPOCH) + float(res["expires_in"])
                st[uid] = u
                tmp = STATE + ".tmp"
                json.dump(st, open(tmp, "w"), ensure_ascii=False)
                os.replace(tmp, STATE)
            # mirror the update into the live dict the running threads hold
            user["accessToken"] = at
            if res.get("refresh_token"):
                user["refreshToken"] = res["refresh_token"]
            user["deviceId"] = dev
            if res.get("expires_in"):
                user["accessTokenExpiry"] = (time.time() - CF_EPOCH) + float(res["expires_in"])
            print(f"[refresh] {uid} OK via dev={dev[:8]} rotated={bool(res.get('refresh_token'))}", flush=True)
            _refresh_next.pop(uid, None)
            return at
        if res.get("error") == "slow_down" or res.get("error_code") == 429:
            saw_slowdown = True
        print(f"[refresh] {uid} dev={dev[:8]} -> {res.get('error')}: {res.get('error_description')}",
              flush=True)
    _refresh_next[uid] = time.time() + (_REFRESH_SLOWDOWN if saw_slowdown else _REFRESH_COOLDOWN)
    return None


def ensure_fresh_token(user):
    """Proactively refresh when we know the access token has (nearly) expired."""
    if token_expired(user):
        vkid_refresh(user)
    return user.get("accessToken")


def vk_get(method, token, user=None, _retry=True, **params):
    url = f"{VK_API}/method/{method}?" + urlencode({**params, "access_token": token, "v": VK_V})
    with urlopen(Request(url), timeout=40) as r:
        body = json.loads(r.read().decode("utf-8"))
    if "error" in body:
        e = body["error"]
        # Token expired: 1117 ("access_token has expired") or 5 ("User authorization failed").
        if e.get("error_code") in (5, 1117) and user is not None and _retry:
            nt = vkid_refresh(user)
            if nt:
                return vk_get(method, nt, user=user, _retry=False, **params)
        raise RuntimeError(f"vk {e.get('error_code')}: {e.get('error_msg')}")
    return body.get("response", {})


def recent_apns(u):
    """The device's recent APNs tokens, newest first (deduped, max 3). A re-signed VK rotates its
    APNs token almost every launch and only the token registered under the real signed identity
    receives our topic=<real bundle> alerts; the relay cannot tell which, so alerts go to the last
    few tokens and 410s prune the dead ones. VoIP is unaffected (its token is stable)."""
    toks = list(u.get("apnsTokens") or [])
    t = u.get("apnsToken")
    if t and t not in toks:
        toks.insert(0, t)
    return [x for x in toks if x][:3]


def send_alert_all(uid, bundle, body, u):
    pc, pk = certs_for(bundle)
    # Read the freshest token history from state (the caller's snapshot can lag behind a new register).
    try:
        fresh = load_state().get(str(uid)) or u
    except Exception:
        fresh = u
    toks = recent_apns(fresh) or recent_apns(u)
    for tok in toks:
        apns_curl(uid, pc, pk, bundle, "alert", tok, body)


def apns_curl(uid, cert, key, topic, ptype, token, body):
    """One APNs HTTP/2 push via curl; prunes a dead token on 410/BadDeviceToken. Shared by the
    message mirror and the notifications poller."""
    try:
        # A stale VoIP push can arrive after the caller hangs up; do not let APNs store it.
        expiration = ["-H", "apns-expiration: 0"] if ptype == "voip" else []
        r = subprocess.run(["curl", "-s", "--http2", "--cert", cert, "--key", key,
                            "-H", f"apns-topic: {topic}", "-H", f"apns-push-type: {ptype}",
                            "-H", "apns-priority: 10", *expiration, "-d", body, "-w", "\n%{http_code}",
                            f"https://api.push.apple.com/3/device/{token}"],
                           capture_output=True, text=True, timeout=10)
        out = (r.stdout or "").rsplit("\n", 1)
        status = out[-1].strip() if len(out) == 2 else ""
        payload = out[0] if len(out) == 2 else (r.stdout or "")
        if status == "200":
            print(f"[push] {uid} {ptype} topic={topic} -> 200 OK", flush=True)
            return
        reason = ""
        try:
            reason = (json.loads(payload) or {}).get("reason", "")
        except Exception:
            pass
        print(f"[push] {uid} {ptype} topic={topic} HTTP {status} {reason}", flush=True)
        if status == "410" or reason in ("Unregistered", "BadDeviceToken"):
            try:
                st = load_state(); u = st.get(uid)
                if u:
                    field = "voipToken" if ptype == "voip" else "apnsToken"
                    changed = False
                    if field == "apnsToken":
                        toks = [x for x in (u.get("apnsTokens") or []) if x and x != token]
                        if toks != (u.get("apnsTokens") or []):
                            u["apnsTokens"] = toks; changed = True
                        if u.get("apnsToken") == token:
                            u["apnsToken"] = toks[0] if toks else ""; changed = True
                    elif u.get(field):
                        u[field] = ""; changed = True
                    if changed: save_state(st)
                    print(f"[push] {uid} pruned {field} ({reason or status})", flush=True)
            except Exception as e:
                print(f"[push] {uid} prune failed: {e}", flush=True)
    except Exception as e:
        print(f"[push] {uid} failed: {e}", flush=True)


# VK's notifications.get returns pre-rendered, already-localized text per item (the уведомления
# feed: likes, comments, mentions, reposts, friend events, group activity, …) in a light markup —
# '''bold''' and [id123|Name] / [club123|Name] links. We strip the markup and show VK's own text,
# so wording and language always match what the app would display.
import re  # noqa: E402

_VK_LINK = re.compile(r"\[(?:id|club|public)-?\d+\|([^\]]+)\]")
_VK_BOLD = re.compile(r"'''(.*?)'''")


def _clean_vk_text(s):
    if not isinstance(s, str):
        return ""
    s = _VK_LINK.sub(r"\1", s)        # [id123|Anastasia] -> Anastasia
    s = _VK_BOLD.sub(r"\1", s)        # '''bold''' -> bold
    s = s.replace("{date}", "").replace("\xa0", " ")
    return re.sub(r"\s+", " ", s).strip()


# A LongPoll message with no text still has an attachment — VK itself shows a type label
# ("📷 Фотография", "Стикер", …) instead of a blank push. These are the labels VK's own UI uses;
# we key off the attachN_type fields of LongPoll index 7 (and a couple of `extra` markers).
_ATTACH_LABELS = {
    "photo": ("📷", "Фотография"), "sticker": ("", "Стикер"),
    "audio_message": ("🎤", "Голосовое сообщение"), "video": ("📹", "Видеозапись"),
    "doc": ("📄", "Документ"), "audio": ("🎵", "Аудиозапись"), "wall": ("📝", "Запись"),
    "wall_reply": ("💬", "Комментарий"), "link": ("🔗", "Ссылка"), "market": ("🛍", "Товар"),
    "market_album": ("🛍", "Товары"), "poll": ("📊", "Опрос"), "gift": ("🎁", "Подарок"),
    "graffiti": ("🎨", "Граффити"), "story": ("📖", "История"), "podcast": ("🎙", "Подкаст"),
    "money_transfer": ("💸", "Денежный перевод"), "money_request": ("💰", "Запрос денег"),
}


def _attachment_label(attach, extra):
    """VK-style label for a message that carries only attachments (no text). '' if none."""
    if extra.get("fwd"):
        return "💬 Пересланные сообщения"
    if "geo" in attach or extra.get("geo"):
        return "📍 Местоположение"
    types = [v for k, v in attach.items() if k.endswith("_type")]
    if not types:
        return ""
    emoji, label = _ATTACH_LABELS.get(types[0], ("📎", "Вложение"))
    out = f"{emoji} {label}".strip() if emoji else label
    if len(types) > 1:
        out += f" (+{len(types) - 1})"
    return out


# A unified notification carries its tap target as action={"type":"custom","url":"https://vk.com/
# wall142304362_1116"} (or photo…, a profile, …). Forwarding that url would make VK open its in-app
# m.vk.ru web view; instead we parse the vk.com object link into VK's OWN native push `type`+keys so
# the tap opens the native screen. Anything we can't map natively gets NO data block -> the tap just
# opens the app (never a web view or an error alert).
_VK_OBJ = re.compile(r"vk\.com/(wall|photo|video|club|public|id|album)(-?\d+)_(\d+)")
_VK_PROFILE = re.compile(r"vk\.com/id(\d+)\b")


def notif_tap_data(item, to_id):
    """Native push `data` for a feed notification's tap, or None to just open the app."""
    act = item.get("action") if isinstance(item.get("action"), dict) else {}
    url = act.get("url") or ""
    data = {"to_id": int(to_id)}
    m = _VK_OBJ.search(url)
    if m:
        kind, owner, oid = m.group(1), int(m.group(2)), int(m.group(3))
        if kind == "wall":
            qs = parse_qs(urlparse(url).query)
            cid = (qs.get("reply") or qs.get("comment") or [None])[0]
            if cid and str(cid).isdigit():   # deep-linked to a comment
                data.update({"type": "comment", "owner_id": owner, "post_id": oid,
                             "item_id": oid, "comment_id": int(cid), "reply_id": int(cid)})
            else:
                data.update({"type": "post", "owner_id": owner, "post_id": oid, "item_id": oid})
            return data
        if kind == "photo":
            data.update({"type": "tag_photo", "owner_id": owner, "photo_id": oid, "item_id": oid})
            return data
    # friend events -> open the user's profile
    if (item.get("icon_type") or "").startswith("friend"):
        pm = _VK_PROFILE.search(url)
        if pm:
            data.update({"type": "friend_found", "user_id": int(pm.group(1)),
                         "from_id": int(pm.group(1))})
            return data
    return None  # unmapped (story/video/club/custom): just open the app, no web view


def format_notification(item):
    """(title, body) from a unified notification, or (None, None) to skip feed-section stubs.
    VK renders the уведомление sentence in `header` (likes/reposts/mentions/friend events have ONLY
    a header; comments add the comment body in `text`). Items with neither are feed sections or
    stubs, which we skip. We show the header as the body (optionally with the comment text)."""
    header = _clean_vk_text(item.get("header"))
    text = _clean_vk_text(item.get("text"))
    if header and text:
        body = f"{header}: {text}"
    else:
        body = header or text
    if not body:
        return None, None
    return "ВКонтакте", body


class NotificationsPoller(threading.Thread):
    """Polls notifications.get and pushes each new уведомление. First poll sets a baseline (no flood
    of old items); afterwards only items newer than the last seen date are pushed."""

    INTERVAL = int(os.environ.get("VKRELAY_NOTIF_INTERVAL", "20"))

    def __init__(self, user):
        super().__init__(daemon=True)
        self.user = user
        self.stop = threading.Event()
        self.seen_after = None

    def run(self):
        while not self.stop.is_set():
            try:
                self.poll()
            except Exception as e:
                print(f"[notif {self.user.get('userId')}] {e}", flush=True)
                self.stop.wait(_backoff_wait(e, self.INTERVAL)); continue
            self.stop.wait(self.INTERVAL)

    def poll(self):
        u = self.user
        token = u["accessToken"]
        bundle = u.get("bundleId", "")
        apns = u.get("apnsToken", "")
        if not (apns and bundle) or not check_entitlement(u.get("udid")):
            return
        token = ensure_fresh_token(u) or token
        resp = vk_get("notifications.get", token, user=u, count=30)
        items = [it for it in resp.get("items", []) if isinstance(it, dict)]
        dates = [it.get("date", 0) for it in items]
        if self.seen_after is None:
            self.seen_after = max(dates) if dates else 0  # baseline — don't push the backlog
            return
        uid = str(u.get("userId"))
        for it in sorted((it for it in items if it.get("date", 0) > self.seen_after), key=lambda x: x.get("date", 0)):
            title, body = format_notification(it)
            if not body:
                continue
            payload = {"aps": {"alert": {"title": title, "body": body}, "sound": "default",
                               "thread-id": "vk-notif"}}
            tap = notif_tap_data(it, uid)       # native tap target (post/photo/comment/profile)
            if tap:
                payload["data"] = tap
            send_alert_all(uid, bundle, json.dumps(payload, ensure_ascii=False), u)
            print(f"[notif] {uid} {it.get('icon_type')}: {body[:48]!r} -> push", flush=True)
        if dates:
            self.seen_after = max(self.seen_after, max(dates))


class Mirror(threading.Thread):
    def __init__(self, user):
        super().__init__(daemon=True)
        self.user = user
        self.stop = threading.Event()
        self._names = {}  # uid -> (name, avatar)
        self._peers = {}  # peer id -> (type, chat title)

    def name_avatar(self, uid):
        if uid not in self._names:
            try:
                users = vk_get("users.get", self.user["accessToken"], user=self.user,
                               user_ids=uid, fields="photo_100")
                u = users[0] if users else {}
                nm = f"{u.get('first_name', '')} {u.get('last_name', '')}".strip() or "VK"
                self._names[uid] = (nm, u.get("photo_100"))
            except Exception:
                self._names[uid] = ("VK", None)
        return self._names[uid]

    def peer_info(self, peer_id, extra=None):
        # VK user IDs can exceed the historical 2-billion chat offset. Ask VK for the
        # actual peer type instead of classifying every large ID as a group conversation.
        if peer_id not in self._peers:
            try:
                result = vk_get("messages.getConversationsById", self.user["accessToken"],
                                user=self.user, peer_ids=peer_id)
                item = (result.get("items") or [])[0]
                kind = (item.get("peer") or {}).get("type")
                if kind in ("user", "chat", "group"):
                    title = (item.get("chat_settings") or {}).get("title")
                    self._peers[peer_id] = (kind, title)
            except Exception:
                pass  # Retry the lookup on the next message rather than caching a guess.
        if peer_id in self._peers:
            return self._peers[peer_id]
        extra = extra or {}
        return ("chat" if extra.get("from") or extra.get("title") else "user", extra.get("title"))

    def push_alert(self, token, bundle, title, text, avatar, peer_id, msg_id=0, sender_id=0,
                   peer_type="user"):
        # No mutable-content: VK's own NotificationServiceExtension crashes on the re-signed build
        # (its App Group container is not under our prefix — Nestor/NSFileManager), and it adds
        # nothing we need, so we show the alert directly.
        #
        # The native show_message payload shows a generic alert on this VK build. Its URL
        # handler opens vk://vk.com/im?sel=<peer> directly in the native conversation instead.
        aps = {"alert": {"title": title, "body": text or "📷"}, "sound": "default",
               "thread-id": f"vk-{peer_id}"}
        payload = {"aps": aps, "data": {"url": f"vk://vk.com/im?sel={int(peer_id)}"}}
        send_alert_all(str(self.user.get("userId")), bundle, json.dumps(payload, ensure_ascii=False), self.user)

    def push_call(self, token, bundle, caller, video=False, peer_id=0):
        # Our own VoIP payload (ruas_call), consumed by VKRelay.dylib's PushKit hook, which reports
        # the call to CallKit itself — VK's vendor call-push decoder is bypassed (it crashes on any
        # non-vendor payload). DETECTION of an incoming VK call (which LongPoll code, or a separate
        # channel) is still unconfirmed and must be wired before this is called from the loop.
        payload = {"aps": {"content-available": 1},
                   "ruas_call": {"caller": caller or "VK", "video": bool(video), "peer_id": peer_id}}
        vc, vk = certs_for(bundle, voip=True)
        self._curl(vc, vk, bundle + ".voip", "voip", token, json.dumps(payload, ensure_ascii=False))

    def _curl(self, cert, key, topic, ptype, token, body):
        apns_curl(str(self.user.get("userId")), cert, key, topic, ptype, token, body)

    def run(self):
        while not self.stop.is_set():
            try:
                self._session()
            except Exception as e:
                print(f"[mirror {self.user.get('userId')}] {e}", flush=True)
                self.stop.wait(_backoff_wait(e, 5)); continue
            # A failure is usually a rotated access token; the hook re-POSTs a fresh one on the next
            # app launch/foreground, which restarts this mirror. Back off meanwhile.
            self.stop.wait(5)

    def _session(self):
        u = self.user
        token = u["accessToken"]
        bundle = u.get("bundleId", "")
        apns = u.get("apnsToken", "")
        last_check = 0.0
        entitled = True
        token = ensure_fresh_token(u) or token
        lp = vk_get("messages.getLongPollServer", token, user=u, need_pts=1, lp_version=3)
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
                attach = upd[7] if len(upd) > 7 and isinstance(upd[7], dict) else {}

                peer_type, chat_title = self.peer_info(peer_id, extra)

                # Incoming call. VK writes a call-log message when the call ENDS (LongPoll index 7:
                # attach1_type=call, attach1_call_state, attach1_call_duration). A live CallKit ring
                # is not deliverable on a re-signed build (VK's ring is a VoIP push we can't receive),
                # so we notify a MISSED call (duration 0). An answered call needs no notification.
                if any(v == "call" for k, v in attach.items() if k.endswith("_type")):
                    if int(attach.get("attach1_call_duration", "0") or 0) > 0:
                        continue
                    caller = (self.name_avatar(peer_id)[0] if peer_type != "chat"
                              else (chat_title or extra.get("title") or "Беседа"))
                    print(f"[call] {u.get('userId')} missed call from {peer_id} -> push", flush=True)
                    self.push_alert(apns, bundle, caller, "📞 Пропущенный звонок", None, peer_id,
                                    msg_id=upd[1], sender_id=int(extra.get("from", 0) or 0),
                                    peer_type=peer_type)
                    continue

                # No text -> show VK's own attachment label ("📷 Фотография", "Стикер", …).
                if not text:
                    text = _attachment_label(attach, extra)

                sender = 0
                if peer_type == "chat":  # group chat: title + real sender from `extra`
                    title = chat_title or extra.get("title") or "Беседа"
                    sender = int(extra.get("from", 0) or 0)
                    if sender:
                        nm, _ = self.name_avatar(sender)
                        text = f"{nm}: {text}" if text else nm
                    _, avatar = (None, None)
                else:
                    title, avatar = self.name_avatar(peer_id)
                print(f"[msg] {u.get('userId')} peer={peer_id} {str(text)[:48]!r} -> push", flush=True)
                self.push_alert(apns, bundle, title, text, avatar, peer_id,
                                msg_id=upd[1], sender_id=sender, peer_type=peer_type)


def _decode_conv_params(conv):
    """conversation_params is a base64 blob carrying the WebRTC join info (okcdn ws2 url, call id,
    stun/turn, expiry). Decode it to bytes/text so its structure can be read from the relay log —
    needed to RE VK's call-join entry point for the answer->audio handoff. Returns (kind, text)."""
    import base64
    raw = base64.b64decode(conv + "=" * (-len(conv) % 4))
    try:
        return "utf8", raw.decode("utf-8"), len(raw)
    except Exception:
        return "hex", raw[:384].hex(), len(raw)


def vk_push_call(uid, bundle, voip_token, value):
    """Send VK's OWN native VoIP push so VK presents CallKit and joins the real call itself (ring +
    audio), no reimplementation. VKRelay.dylib leaves a `data`-shaped payload for VK's own
    `-[PKPushManager pushRegistry:didReceiveIncomingPush]` to handle.

    EXACT shape VK parses (RE of VKClient 8.183, research/vk-native-push-formats-2026-10-23.md):
      pushRegistry   -> payload["data"]
      AppSessionManager receivedVoipPushPayload -> data["context"] + data["to_id"]
      VKCallsService handleVoIPPushPayload(context) -> +[VKCPushPayload payloadFromDictionary:context]
      payloadFromDictionary reads context["caller_info"], ["call_id"], ["conversation_params"],
        ["answer_from"] (STRING), ["chat_info"], ["p2p_forbidden"], ["payload"]; callerId =
        caller_info["user_id"].
    We used to send these flat under `data` (no `context`), so VK read context=nil -> callerInfo=nil
    -> `[NSSet setWithObjects: callerInfo]` crashed (NSPlaceholderSet nil object). Now wrapped in
    `context` with to_id alongside, and answer_from as a string."""
    ci = value.get("caller_info", {}) or {}
    context = {
        "call_id": value.get("call_id"),
        "caller_info": ci,
        "answer_from": str(value.get("from_id") or value.get("answer_from") or ci.get("user_id") or ""),
        "p2p_forbidden": bool(value.get("p2p_forbidden", False)),
    }
    if value.get("payload") is not None:
        context["payload"] = value["payload"]          # "{\"is_video\":false,...}" (JSON string)
    if value.get("chat_info") is not None:
        context["chat_info"] = value["chat_info"]
    conv = value.get("conversation_params")
    # Static RE of VKClient 8.183 (2026-11-05): +[VKCPushPayload payloadFromDictionary:] reads
    # call_id/caller_info/conversation_params/chat_info/answer_from/p2p_forbidden DIRECTLY from the
    # dict it is given; -[PKPushManager pushRegistry:didReceiveIncomingPush] passes `data` straight
    # to the delegate and reads no "context" key anywhere in the handle chain, and there is no
    # AppSessionManager receivedVoipPushPayload symbol. So VK reads these FLAT under `data`. We send
    # them flat AND keep a `context` copy so an older/other reader still finds non-nil fields (no
    # [NSSet setWithObjects: nil] crash) — whichever level VK reads, the call is well-formed.
    data = {"to_id": int(uid)}
    data.update(context)                 # flat: call_id, caller_info, answer_from, p2p_forbidden, (payload, chat_info)
    data["context"] = dict(context)      # backward-compat copy (small fields only; conv added below)
    if conv:
        data["conversation_params"] = conv
    payload = {"data": data}
    # VoIP pushes cap at ~5 KB; conversation_params is the big field. If over, drop it (VK re-fetches
    # the call by call_id once it is awake). It lives only at the flat level, so one delete suffices.
    if conv and len(json.dumps(payload, ensure_ascii=False).encode()) > VOIP_MAX_BYTES:
        data.pop("conversation_params", None)
        print(f"[call] {uid} conv_params dropped (payload > {VOIP_MAX_BYTES}B); call_id only", flush=True)
    vc, vk = certs_for(bundle, voip=True)
    apns_curl(uid, vc, vk, bundle + ".voip", "voip", voip_token, json.dumps(payload, ensure_ascii=False))


class CallsPoller(threading.Thread):
    """Subscribes to the user's VK calls queue and, on an incoming call, sends our own VoIP push so
    the re-signed app rings via CallKit even when it is killed/backgrounded (VK's own VoIP push can
    never reach our bundle). Uses the stored mobile access token (client_id 3140623) — no web auth.
    Device-confirmed: with the app KILLED, queue.subscribe(calls_<uid>_3140623_1) + a_check delivers
    `{"data":{"type":"incoming_call","value":{call_id,caller_info,payload:{is_video}}}}` in real time."""

    CLIENT_ID = "3140623"

    def __init__(self, user):
        super().__init__(daemon=True)
        self.user = user
        self.stop = threading.Event()
        self._rung = set()  # call_ids already pushed, so one call rings once

    def _subscribe(self, token, uid, _retry=True):
        qid = "calls_%s_%s_1" % (uid, self.CLIENT_ID)
        url = f"{VK_API}/method/queue.subscribe?" + urlencode(
            {"queue_ids": qid, "access_token": token, "v": VK_V, "client_id": self.CLIENT_ID})
        r = json.loads(urlopen(Request(url), timeout=20).read().decode("utf-8"))
        if "error" in r:
            if r["error"].get("error_code") in (5, 1117) and _retry:   # token expired -> refresh + retry
                nt = vkid_refresh(self.user)
                if nt:
                    return self._subscribe(nt, uid, _retry=False)
            raise RuntimeError("queue.subscribe: " + str(r["error"].get("error_msg")))
        q = r["response"]["queues"][0]
        return r["response"]["base_url"], q["key"], q["timestamp"]

    def run(self):
        while not self.stop.is_set():
            try:
                self._session()
            except Exception as e:
                print(f"[calls {self.user.get('userId')}] {e}", flush=True)
                self.stop.wait(_backoff_wait(e, 4)); continue
            self.stop.wait(4)

    def _session(self):
        u = self.user
        uid = u["userId"]
        token = u["accessToken"]
        bundle = u.get("bundleId", "")
        voip = u.get("voipToken", "")
        if not (voip and bundle):
            return  # calls ring via the VoIP token; nothing to do without it
        token = ensure_fresh_token(u) or token
        base, key, t = self._subscribe(token, uid)
        last_check = 0.0
        entitled = True
        while not self.stop.is_set():
            url = base + "?" + urlencode({"act": "a_check", "key": key, "ts": t, "id": uid, "wait": 25})
            with urlopen(Request(url), timeout=40) as r:
                res = json.loads(r.read().decode("utf-8"))
            if "failed" in res:
                if res["failed"] == 1 and "ts" in res:
                    t = res["ts"]; continue
                base, key, t = self._subscribe(token, uid); continue  # key expired → re-subscribe
            t = res.get("ts", t)
            now = time.time()
            if now - last_check > ENT_TTL:
                entitled = check_entitlement(u.get("udid")); last_check = now
            if not entitled:
                continue
            for ev in res.get("events", []):
                data = ev.get("data", {}) if isinstance(ev, dict) else {}
                if data.get("type") != "incoming_call":
                    continue
                val = data.get("value", {}) or {}
                ci = val.get("caller_info", {}) or {}
                # Skip our OWN outgoing call (the queue echoes it back): the caller is ourselves.
                if str(ci.get("user_id")) == str(uid) or str(val.get("from_id")) == str(uid):
                    continue
                cid = val.get("call_id")
                if cid and cid in self._rung:
                    continue
                if cid:
                    self._rung.add(cid)
                caller = f"{ci.get('first_name', '')} {ci.get('last_name', '')}".strip() or "VK"
                try:
                    video = bool(json.loads(val.get("payload", "{}")).get("is_video"))
                except Exception:
                    video = False
                print(f"[call] {uid} incoming from {caller} (video={video}) -> voip push (ring)",
                      flush=True)
                vk_push_call(str(uid), bundle, voip, value=val)


def _stop(threads):
    for th in (threads or []):
        try:
            th.stop.set()
        except Exception:
            pass


# TEST-ONLY MODE: VK notifications + calls are not in full production yet, so the relay serves ONLY
# these test devices (matched by the device UDID the hook reports). Every other customer gets NO
# mirror — no notifications, no calls — while their re-signed VK still carries the Face-ID-loop fix
# from the dylib. Empty this set to serve everyone (full production rollout).
TEST_ONLY_UDIDS = {
    "00008140-00145D163C69801C",  # rovsen.rzayev.2009@gmail.com (iPhone17,1) — USB test device
    "00008130-00125D492130001C",  # dmitriy@mail.ru (iPhone16,1) — 2nd test device
}


def start_or_update(user):
    uid = norm_uid(user.get("userId"))
    if not (uid and user.get("accessToken")):
        return
    if TEST_ONLY_UDIDS and (user.get("udid") or "") not in TEST_ONLY_UDIDS:
        with _lock:
            old = _mirrors.pop(uid, None)
        _stop(old)
        return  # not a test device: no notifications/calls (Face-ID fix still ships in the dylib)
    if not check_entitlement(user.get("udid")):
        with _lock:
            old = _mirrors.pop(uid, None)
        _stop(old)
        print(f"[relay] user {uid} not entitled; mirror off", flush=True)
        return
    with _lock:
        current = _mirrors.get(uid)
        # APNs and PushKit callbacks commonly submit the same registration several times.
        # Restarting healthy workers for each one discards their poll cursors and overlaps
        # in-flight requests. Only replace them when the actual registration has changed.
        comparable = lambda data: {k: v for k, v in data.items() if k != "updatedAt"}
        if (current and all(th.is_alive() and not th.stop.is_set() for th in current)
                and comparable(current[0].user) == comparable(user)):
            return
        _stop(current)
        # messages (real-time LongPoll) + уведомления feed + incoming-call ring (calls queue)
        threads = [Mirror(user), NotificationsPoller(user), CallsPoller(user)]
        _mirrors[uid] = threads
        for th in threads:
            th.start()


def bundle_has_cert(b):
    return bool(b) and os.path.isdir(os.path.join(CERTS_DIR, b))


def merge_registration(previous, incoming):
    """Keep push tokens during partial registrations from the same installation."""
    merged = dict(incoming)
    same_install = (previous.get("udid") and previous.get("udid") == incoming.get("udid")
                    and previous.get("bundleId") and previous.get("bundleId") == incoming.get("bundleId"))
    if same_install:
        for field in ("apnsToken", "voipToken"):
            if not merged.get(field) and previous.get(field):
                merged[field] = previous[field]
    # Keep a short history of recent APNs tokens (newest first) so alerts can be fanned out across
    # the token the re-signed app rotates through each launch.
    toks = list(previous.get("apnsTokens") or [])
    for t in ([previous.get("apnsToken")] if not same_install else []):
        pass
    newtok = merged.get("apnsToken")
    if newtok:
        toks = [newtok] + [x for x in toks if x and x != newtok]
    merged["apnsTokens"] = toks[:3]
    return merged


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
        # Diagnostic beacon from the injected dylib (no userId needed): just log it. Lets us see the
        # dylib's runtime state on device without readable device logs (iOS 27 redacts app os_log).
        if isinstance(user, dict) and user.get("diag"):
            print(f"[diag] {user.get('diag')} udid=...{str(user.get('udid'))[-6:]} exe={user.get('exe')} build={user.get('build')}", flush=True)
            self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(b'{"ok":true}'); return
        uid = norm_uid(user.get("userId"))
        if not uid:
            self.send_response(422); self.end_headers(); return
        user["userId"] = uid
        # Concurrent APNs and PushKit callbacks can arrive in either order. Serialize the
        # read/update/restart so a partial registration cannot erase the other push token.
        with _register_lock:
            state = load_state()
            user = merge_registration(state.get(uid, {}), user)
            prev_b = state.get(uid, {}).get("bundleId")
            if not bundle_has_cert(user.get("bundleId")) and bundle_has_cert(prev_b):
                print(f"[relay] keep real bundle {prev_b} over {user.get('bundleId')} for {uid}", flush=True)
                user["bundleId"] = prev_b
            state[uid] = {**user, "updatedAt": time.time()}
            save_state(state)
            start_or_update(state[uid])
        print(f"[relay] registered user {uid} bundle={user.get('bundleId')} "
              f"apns={str(user.get('apnsToken',''))[:10]} voip={str(user.get('voipToken',''))[:10]}", flush=True)
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
                    _stop(m)
                    print(f"[relay] user {uid} subscription lapsed; mirror stopped", flush=True)
            except Exception as e:
                print(f"[supervisor] {uid}: {e}", flush=True)


if __name__ == "__main__":
    for uid, user in load_state().items():
        start_or_update(user)
    threading.Thread(target=supervisor, daemon=True).start()
    print(f"[relay] listening on 127.0.0.1:{PORT}, {len(_mirrors)} mirrors restored", flush=True)
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
