# MAX integration — what we set up wrong / incompletely

Audit from the RE (`MAX-RE.md`) vs our relay (`/opt/mxrelay/mxrelay.py`) + inject dylibs. Each item:
native behaviour (evidence) → what we do → impact → fix. Confidence is marked; items tagged
**VERIFY** need a check on our *signed* build (NSE entitlements / App Group) which this static RE of the
vendor binary can't settle alone.

## Confirmed gaps (from native RE + our known payload)

1. **Notifications don't clear after you read the chat.** (the reported bug)
   Native: main app calls `removeDeliveredNotifications` / `getDeliveredNotifications`, keeps read state
   (`markAsRead`, `readMessages`, `setLastRead`, NSE `batchRead`); reading a chat (here or on another
   device, via server read-sync) withdraws its notifications. Us: the relay is a **one-way mirror** — it
   pushes on a new message but never withdraws, and has no read-state. → notifications stay after
   opening/reading and pile up. Fix: our mirror connection already sees the account's traffic — detect
   the user's own read/`setLastRead` event and either send a background/silent push that withdraws
   (`removeDeliveredNotifications(withIdentifiers:)` keyed by `msgid`/`mc`), or have our injected dylib
   withdraw delivered notifications for a chat when it's opened. (Set a stable push identifier so it can
   be targeted.)

2. **No badge count.** Native maintains the unread app badge. Our push sets no `aps.badge`, and nothing
   clears it → badge is wrong/stuck. Fix: carry an unread count from the mirror into `aps.badge`, and
   push `badge:0` (or withdraw) on read.

3. **No notification grouping.** Native sets `threadIdentifier` (per-chat grouping). Our push sets only
   `data.mc`, not `aps.thread-id` → every message is its own ungrouped banner. Fix: set
   `aps.thread-id = <chatId>` (and a `apns-collapse-id` for same-chat coalescing if desired).

4. **No custom sound / only `default`.** Minor. Native honours per-chat sound settings; we always send
   `sound:"default"`.

## High-impact, VERIFY on our signed build

5. **Communication notifications (sender avatar + name) likely broken.** Native NSE
   (`ru.oneme.app.nse`) downloads the sender avatar, attaches it (`UNNotificationAttachment`) and applies
   an `INSendMessageIntent` communication intent → avatar + sender styling. That needs the
   `com.apple.developer.usernotifications.communication` entitlement, which re-signing usually drops
   (our Apple App IDs must have it enabled). We already send `avatar_url` + `mutable-content:1`, so if the
   entitlement/NSE is intact it would work. VERIFY: does our signed MAX's NSE keep the communication
   entitlement? If not → plain notifications, no avatar.

6. **NSE ↔ app App Group almost certainly broken on overflow teams.** The NSE uses a shared App Group
   (`sharedUserDefaults`, `AvatarStore`) to share avatar cache + read state with the app. Overflow teams
   have NO App Groups ([[overflow-teams-no-app-groups-20261008]]) → the NSE's shared storage fails →
   degraded notifications (no cached avatar, read-state not shared) for every customer on an overflow
   team. VERIFY per team; this compounds #1 and #5.

7. **In-notification quick reply likely missing/non-functional.** Native has `OKMPushReplyTask`
   (`initWithMessage:chatId:`, sends via the session) behind a reply notification action. For it to work
   our build must (a) register the `Message`/`ChatMessage` categories with a `UNTextInputNotificationAction`,
   and (b) be able to send the reply — which needs a live session when the app is backgrounded. VERIFY:
   does the reply button appear, and does a reply actually send through our relay/session? Likely a gap.

8. **NSE bundle re-prefix / aps-topic.** The NSE bundle id is `ru.oneme.app.nse`; it must be re-signed
   with our prefix + a matching nested provisioning profile (SigningService handles nested profiles), and
   the push topic for it must be correct, or the NSE never runs (cf. the main-app entitlement block
   [[max-notification-entitlement-block-20261021]]). VERIFY on a signed build.

## Partial / by-design

9. **Delivery & read receipts to the other party lag.** Our mirror keepalives with `interactive=0` so it
   won't mark the user online, and it doesn't send delivered/read back → the sender's "delivered/read"
   and presence are only updated once the user opens MAX. Mostly by-design for a passive mirror; note it.

10. **Missed/declined call handling & call history.** Native has `OMCallHistoryUI` + missed-call
    notifications. Our relay pushes an incoming VoIP but may not produce a missed-call notification or a
    call-history entry when unanswered/declined. VERIFY; likely a gap.

11. **Message-type coverage.** We push text + attachment labels (sticker/photo/video/voice/music/gif/
    file/contact/location/poll — good). Not pushed: scheduled/"delayed" messages
    (`DELAYED_MESSAGES_NOTIFICATION_SAVED_SENDER_FORMAT` exists in the NSE), edits, reactions. Mostly fine
    to skip, but "delayed messages" may surprise users.

12. **Call audio route on background answer** — the speaker bug, root-caused in `MAX-RE.md` §3.

## What we got right
Tap opens the correct chat (`category` + `mc` + `msgid` reverse-engineered to match MAX's own format),
attachment-type labels, basic per-chat mute (`muted` skip), calls ring via native CallKit (diyorde
call-hook neutralised), per-device VoIP dedup, per-team push/voip certs.
