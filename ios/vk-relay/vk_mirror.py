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
# APNs VoIP payloads cap at 5120 bytes; stay under it with headroom so the push is never rejected.
VOIP_MAX_BYTES = 4800

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


def apns_curl(uid, cert, key, topic, ptype, token, body):
    """One APNs HTTP/2 push via curl; prunes a dead token on 410/BadDeviceToken. Shared by the
    message mirror and the notifications poller."""
    try:
        r = subprocess.run(["curl", "-s", "--http2", "--cert", cert, "--key", key,
                            "-H", f"apns-topic: {topic}", "-H", f"apns-push-type: {ptype}",
                            "-H", "apns-priority: 10", "-d", body, "-w", "\n%{http_code}",
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
                    if u.get(field):
                        u[field] = ""; save_state(st)
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

    INTERVAL = int(os.environ.get("VKRELAY_NOTIF_INTERVAL", "45"))

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
            self.stop.wait(self.INTERVAL)

    def poll(self):
        u = self.user
        token = u["accessToken"]
        bundle = u.get("bundleId", "")
        apns = u.get("apnsToken", "")
        if not (apns and bundle) or not check_entitlement(u.get("udid")):
            return
        resp = vk_get("notifications.get", token, count=30)
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
            data = {"aps": {"alert": {"title": title, "body": body}, "sound": "default", "thread-id": "vk-notif"}}
            apns_curl(uid, *certs_for(bundle), bundle, "alert", apns, json.dumps(data, ensure_ascii=False))
            print(f"[notif] {uid} {it.get('icon_type')}: {body[:48]!r} -> push", flush=True)
        if dates:
            self.seen_after = max(self.seen_after, max(dates))


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

    def push_alert(self, token, bundle, title, text, avatar, peer_id, msg_id=0, sender_id=0):
        # No mutable-content: VK's own NotificationServiceExtension crashes on the re-signed build
        # (its App Group container is not under our prefix — Nestor/NSFileManager), and it adds
        # nothing we need, so we show the alert directly.
        #
        # Native tap routing: VK's `-[VKAPNSManager parseCategoryFromPayload:]` reads userInfo["data"]
        # ["type"] and routes to a VKAPNSHandler; `type="show_message"` opens the native dialog from
        # the FLAT keys chat_id/sender_id/msg_id (RE: research/vk-native-push-formats-2026-10-23.md).
        # The base handler's web fallback fires only when there is NO recognised type (it opens
        # data["url"]/["external_url"] in an in-app m.vk.ru browser) — which is why a data-less push
        # used to land on m.vk.ru.  We send `data` with the native type and NO url.
        aps = {"alert": {"title": title, "body": text or "📷"}, "sound": "default",
               "thread-id": f"vk-{peer_id}"}
        data = {"type": "show_message", "to_id": int(str(self.user.get("userId")))}
        if msg_id:
            data["msg_id"] = int(msg_id)
        if peer_id >= 2_000_000_000:          # group chat: chat_id = peer - 2e9 (+ real sender)
            data["chat_id"] = int(peer_id) - 2_000_000_000
            if sender_id:
                data["sender_id"] = int(sender_id)
        else:                                  # direct message: the conversation peer
            data["sender_id"] = int(peer_id)
        payload = {"aps": aps, "data": data}
        pc, pk = certs_for(bundle)
        self._curl(pc, pk, bundle, "alert", token, json.dumps(payload, ensure_ascii=False))

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
                attach = upd[7] if len(upd) > 7 and isinstance(upd[7], dict) else {}

                # Incoming call. VK writes a call-log message when the call ENDS (LongPoll index 7:
                # attach1_type=call, attach1_call_state, attach1_call_duration). A live CallKit ring
                # is not deliverable on a re-signed build (VK's ring is a VoIP push we can't receive),
                # so we notify a MISSED call (duration 0). An answered call needs no notification.
                if any(v == "call" for k, v in attach.items() if k.endswith("_type")):
                    if int(attach.get("attach1_call_duration", "0") or 0) > 0:
                        continue
                    caller = (self.name_avatar(peer_id)[0] if peer_id < 2_000_000_000
                              else (extra.get("title") or "Беседа"))
                    print(f"[call] {u.get('userId')} missed call from {peer_id} -> push", flush=True)
                    self.push_alert(apns, bundle, caller, "📞 Пропущенный звонок", None, peer_id,
                                    msg_id=upd[1], sender_id=int(extra.get("from", 0) or 0))
                    continue

                # No text -> show VK's own attachment label ("📷 Фотография", "Стикер", …).
                if not text:
                    text = _attachment_label(attach, extra)

                sender = 0
                if peer_id >= 2_000_000_000:  # group chat: title + real sender from `extra`
                    title = extra.get("title") or "Беседа"
                    sender = int(extra.get("from", 0) or 0)
                    if sender:
                        nm, _ = self.name_avatar(sender)
                        text = f"{nm}: {text}" if text else nm
                    _, avatar = (None, None)
                else:
                    title, avatar = self.name_avatar(peer_id)
                print(f"[msg] {u.get('userId')} peer={peer_id} {str(text)[:48]!r} -> push", flush=True)
                self.push_alert(apns, bundle, title, text, avatar, peer_id,
                                msg_id=upd[1], sender_id=sender)


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
    if conv:
        context["conversation_params"] = conv
    payload = {"data": {"to_id": int(uid), "context": context}}
    # VoIP pushes cap at ~5 KB; conversation_params is the big field. If over, drop it (VK re-fetches
    # the call by call_id once it is awake).
    if conv and len(json.dumps(payload, ensure_ascii=False).encode()) > VOIP_MAX_BYTES:
        del context["conversation_params"]
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

    def _subscribe(self, token, uid):
        qid = "calls_%s_%s_1" % (uid, self.CLIENT_ID)
        url = f"{VK_API}/method/queue.subscribe?" + urlencode(
            {"queue_ids": qid, "access_token": token, "v": VK_V, "client_id": self.CLIENT_ID})
        r = json.loads(urlopen(Request(url), timeout=20).read().decode("utf-8"))
        if "error" in r:
            raise RuntimeError("queue.subscribe: " + str(r["error"].get("error_msg")))
        q = r["response"]["queues"][0]
        return r["response"]["base_url"], q["key"], q["timestamp"]

    def run(self):
        while not self.stop.is_set():
            try:
                self._session()
            except Exception as e:
                print(f"[calls {self.user.get('userId')}] {e}", flush=True)
            self.stop.wait(10)

    def _session(self):
        u = self.user
        uid = u["userId"]
        token = u["accessToken"]
        bundle = u.get("bundleId", "")
        voip = u.get("voipToken", "")
        if not (voip and bundle):
            return  # calls ring via the VoIP token; nothing to do without it
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
        _stop(_mirrors.get(uid))
        # messages (real-time LongPoll) + уведомления feed + incoming-call ring (calls queue)
        threads = [Mirror(user), NotificationsPoller(user), CallsPoller(user)]
        _mirrors[uid] = threads
        for th in threads:
            th.start()


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
        uid = norm_uid(user.get("userId"))
        if not uid:
            self.send_response(422); self.end_headers(); return
        user["userId"] = uid
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
