#!/usr/bin/env python3
# VK flow diagnostic — READ ONLY. Sends NO APNs, writes NO relay state. Uses the test users'
# already-stored access tokens (from the relay's users.json) to capture, from the LIVE VK API,
# the real shapes of the flows customers complain about:
#   (1) notifications.get feed: each item's absolute date, "N days ago", type, icon_type, text
#       -> answers "why do 4-day-old posts arrive" (is VK returning old-dated items? how far back?)
#   (2) recent messages' attachment TYPES (photo/sticker/audio_message/doc/wall/graffiti/...)
#       -> answers "how are image vs sticker differentiated" (so we can label pushes like VK does)
#   (3) a short live LongPoll a_check loop: capture raw code-4 update arrays as they really arrive
#       (this is the exact wire shape the dylib/mirror consume)
# Output: /opt/vkrelay/diag/vk-flow-<ts>.{json,txt}. Safe to run anytime; no side effects.
import os, sys, json, time, datetime
from urllib.parse import urlencode
from urllib.request import Request, urlopen

STATE = os.environ.get("VK_STATE", "/opt/vkrelay/users.json")
OUT_DIR = os.environ.get("VK_DIAG_DIR", "/opt/vkrelay/diag")
VK_API = "https://api.vk.com"
VK_V = "5.207"
LP_SECONDS = int(os.environ.get("VK_LP_SECONDS", "90"))   # how long to watch live LongPoll per user
NOTIF_COUNT = int(os.environ.get("VK_NOTIF_COUNT", "50"))

os.makedirs(OUT_DIR, exist_ok=True)


def vk_get(method, token, **params):
    url = f"{VK_API}/method/{method}?" + urlencode({**params, "access_token": token, "v": VK_V})
    with urlopen(Request(url), timeout=40) as r:
        body = json.loads(r.read().decode("utf-8"))
    if "error" in body:
        e = body["error"]
        raise RuntimeError(f"vk {e.get('error_code')}: {e.get('error_msg')}")
    return body.get("response", {})


def ago(ts):
    try:
        ts = int(ts)
    except Exception:
        return "?"
    d = time.time() - ts
    if d < 0:
        return "future"
    days = d / 86400.0
    if days >= 1:
        return f"{days:.1f}d ago"
    return f"{d/3600.0:.1f}h ago"


def norm_uid(v):
    if isinstance(v, dict):
        v = v.get("value")
    return str(v) if v is not None else ""


def diag_notifications(token, rep):
    rep.append("=== notifications.get (feed: likes/comments/mentions/posts/...) ===")
    try:
        resp = vk_get("notifications.get", token, count=NOTIF_COUNT)
    except Exception as e:
        rep.append(f"  ERROR: {e}")
        return
    items = [it for it in resp.get("items", []) if isinstance(it, dict)]
    rep.append(f"  returned {len(items)} items; last_viewed={resp.get('last_viewed')} "
               f"({ago(resp.get('last_viewed'))})")
    oldest = newest = None
    for it in items:
        d = it.get("date", 0)
        oldest = d if oldest is None else min(oldest, d)
        newest = d if newest is None else max(newest, d)
        txt = (it.get("text") or "")
        if isinstance(txt, str):
            txt = txt.replace("\n", " ")[:70]
        rep.append(f"  - {datetime.datetime.fromtimestamp(d):%Y-%m-%d %H:%M} ({ago(d)}) "
                   f"type={it.get('type')} icon={it.get('icon_type')} "
                   f"header={str(it.get('header'))[:40]!r} text={txt!r}")
    if items:
        span = (newest - oldest) / 86400.0
        rep.append(f"  >> date span across {len(items)} items: {span:.1f} days "
                   f"(oldest {ago(oldest)}, newest {ago(newest)})")
        rep.append("  >> INTERPRETATION: if the newest item is already several days old, VK itself "
                   "is not producing fresh feed activity; a post-update 'flood' is the client "
                   "catching up on this backlog, not our relay (relay only pushes date>baseline).")


def diag_attachments(token, rep):
    rep.append("=== recent conversations -> message attachment TYPES ===")
    try:
        convs = vk_get("messages.getConversations", token, count=20)
    except Exception as e:
        rep.append(f"  ERROR getConversations: {e}")
        return
    seen_types = {}
    examples = []
    for c in convs.get("items", []):
        lm = c.get("last_message") or {}
        peer = (c.get("conversation") or {}).get("peer", {})
        peer_id = peer.get("id")
        try:
            hist = vk_get("messages.getHistory", token, peer_id=peer_id, count=20)
        except Exception as e:
            rep.append(f"  peer {peer_id}: getHistory ERROR {e}")
            continue
        for m in hist.get("items", []):
            atts = m.get("attachments") or []
            fwd = m.get("fwd_messages") or []
            reply = m.get("reply_message")
            body = (m.get("text") or "").strip()
            kinds = [a.get("type") for a in atts if isinstance(a, dict)]
            if fwd:
                kinds.append(f"fwd_messages x{len(fwd)}")
            if reply:
                kinds.append("reply_message")
            for k in kinds:
                seen_types[k] = seen_types.get(k, 0) + 1
            if kinds and len(examples) < 25:
                # show the sub-structure of the first attachment so we learn the label source
                a0 = atts[0] if atts else {}
                sub = {}
                if isinstance(a0, dict):
                    t = a0.get("type")
                    inner = a0.get(t, {}) if t else {}
                    if isinstance(inner, dict):
                        sub = {k: inner.get(k) for k in ("sticker_id", "product_id", "duration",
                                                          "title", "ext", "access_key") if k in inner}
                examples.append((peer_id, body[:30], kinds, sub))
    rep.append(f"  attachment/forward/reply type histogram: {json.dumps(seen_types, ensure_ascii=False)}")
    rep.append("  examples (peer, text, kinds, first-attach-subfields):")
    for peer_id, body, kinds, sub in examples:
        rep.append(f"    peer={peer_id} text={body!r} kinds={kinds} sub={sub}")
    rep.append("  >> Each 'type' here is what LongPoll code-4 index-7 reports as attach1_type=<type>. "
               "Map these to VK-style labels (photo->'📷 Фото', sticker->'Стикер', "
               "audio_message->'🎤 Голосовое сообщение', doc->'📎 Файл', wall->'Запись', "
               "graffiti->'Граффити', gift->'Подарок', ...) in push_alert.")


def diag_longpoll(token, uid, rep):
    rep.append(f"=== live LongPoll a_check for {LP_SECONDS}s (raw code-4 arrays) ===")
    try:
        lp = vk_get("messages.getLongPollServer", token, need_pts=1, lp_version=3)
        server, key, ts = lp["server"], lp["key"], lp["ts"]
    except Exception as e:
        rep.append(f"  ERROR getLongPollServer: {e}")
        return
    deadline = time.time() + LP_SECONDS
    seen = 0
    while time.time() < deadline:
        url = f"https://{server}?" + urlencode(
            {"act": "a_check", "key": key, "ts": ts, "wait": 25, "mode": 2 | 8 | 64, "version": 3})
        try:
            with urlopen(Request(url), timeout=40) as r:
                res = json.loads(r.read().decode("utf-8"))
        except Exception as e:
            rep.append(f"  a_check ERROR: {e}")
            break
        if "failed" in res:
            if res["failed"] == 1 and "ts" in res:
                ts = res["ts"]; continue
            rep.append(f"  LongPoll failed={res['failed']} (key/ts expired) — stopping")
            break
        ts = res.get("ts", ts)
        for upd in res.get("updates", []):
            if upd and upd[0] == 4:
                seen += 1
                rep.append(f"  code4 raw: {json.dumps(upd, ensure_ascii=False)[:400]}")
    rep.append(f"  >> captured {seen} incoming code-4 message events in {LP_SECONDS}s. "
               "Send yourself a photo and a sticker during this window to capture their exact "
               "index-7 attach shape (attach1_type=photo vs sticker).")


def main():
    try:
        state = json.load(open(STATE))
    except Exception as e:
        print(f"cannot read state {STATE}: {e}", file=sys.stderr)
        sys.exit(1)
    ts = time.strftime("%Y%m%d-%H%M%S")
    rep = [f"VK FLOW DIAGNOSTIC {ts} (read-only; no push, no state change)",
           f"state={STATE} users={len(state)}", ""]
    for uid, u in state.items():
        if not isinstance(u, dict):
            continue
        token = u.get("accessToken")
        nuid = norm_uid(u.get("userId") or uid)
        rep.append("#" * 72)
        rep.append(f"USER {nuid}  udid={u.get('udid')}  bundle={u.get('bundleId')}  "
                   f"apns={'y' if u.get('apnsToken') else 'n'} voip={'y' if u.get('voipToken') else 'n'}")
        rep.append("#" * 72)
        if not token:
            rep.append("  no accessToken stored — skipping")
            continue
        try:
            me = vk_get("users.get", token)
            rep.append(f"  token OK: {me}")
        except Exception as e:
            rep.append(f"  token DEAD ({e}) — skipping (user must reopen VK to refresh)")
            continue
        diag_notifications(token, rep)
        rep.append("")
        diag_attachments(token, rep)
        rep.append("")
        diag_longpoll(token, nuid, rep)
        rep.append("")
    txt = "\n".join(rep)
    base = os.path.join(OUT_DIR, f"vk-flow-{ts}")
    open(base + ".txt", "w").write(txt)
    print(f"WROTE {base}.txt")
    print(txt)


if __name__ == "__main__":
    main()
