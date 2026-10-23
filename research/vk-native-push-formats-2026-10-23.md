# VK native push/VoIP payload formats — RE of VKClient 8.183 (2026-10-23)

Goal: make re-signed VK notifications open the **native** screen (not an in-app m.vk.ru browser)
and make relayed calls connect **audio** (not just ring). Both reduce to one thing: send VK its
**own native payload format** and let VK's own code route/handle it.

Source binary: `research/vk-ipa/VK-8.183-source.ipa` (pulled from prod art 355, the decrypted
vendor IPA; `cryptid 0`). Analyzer note: 231 MB arm64 with **LC_DYLD_CHAINED_FIXUPS
(ptr_format 6 = DYLD_CHAINED_PTR_64_OFFSET)** + relative method lists → otool/radare2/rabin2 all
parse **0** ObjC classes, and Ghidra times out (>330 s). Class→selector→imp map was recovered with
a custom parser (`scratchpad/objc_dump.py`); string operands per function with
`scratchpad/fn.py` (r2 `pdf` + chained-fixup cfstring/cstring resolver). IMAGE_BASE = 0x100000000.

## Notification routing (fixes the "tap opens m.vk.ru in-app browser")

Parser: `-[VKAPNSManager parseCategoryFromPayload:]` reads top-level keys **`aps`**, **`category`**,
**`data`**, then **`type`** *from inside `data`*. `type` selects a `VKAPNSHandler<X>` subclass via the
registry built in `+[VKAPNSManager initialize]`.

Full `type` → handler registry (snake_case):
`friend, friend_found, gift, group_invite, app_request, sdk_open, post, tag_photo, comment,`
`validate_device, validate_action, show_message, erase, erase_message, erase_messages,`
`community_msg_del, community_msg_del_till, channel_activation_erase, msg_reaction_del,`
`restore_instant_auth_by_notify, video_upload, headphones_connected, call, missed_call, mask,`
`music_background_limit, wake_up`.

- **New message → `type = "show_message"`** (handler `VKAPNSHandlerShowMessage` : `VKAPNSHandlerMessage`).
  Context keys read **flat from `data`** (NOT nested under a `context` dict), confirmed from the
  accessors: `chat_id`, `sender_id` (`-dialogId`), `msg_id` (`-messageId`), `group_host_id`,
  `conversation_message_id`. `-[VKAPNSHandlerShowMessage handle]` (the tap action) navigates to the
  native dialog; `-notify` (banner) is what reads `title/message/button/url`.
- **Incoming call alert → `type = "call"`** (`VKAPNSHandlerIncomingCall`); missed → `missed_call`.

**Root cause of m.vk.ru:** `-[VKAPNSHandler handle]` (the BASE fallback) opens `data["url"]` /
`data["external_url"]` in a web view. Our relay was sending **no `data` block at all** → no `type`
→ VK had no native target → fell through to the web/feed. Fix = send the `data` block below and
never include `url`/`external_url`.

Native message push:
```json
{ "aps": {"alert":{"title":"..","body":".."}, "sound":"default"},
  "data": { "type":"show_message", "to_id":<recipient uid>,
            "sender_id":<DM peer id>,            // DM: conversation peer
            "chat_id":<peer-2000000000>,         // group only
            "msg_id":<message id>,
            "conversation_message_id":<cmid?> } }
```

## Calls (fixes "answer → no audio, caller stuck waiting")

VK's own VoIP entry (both device-native and what we should drive):
`-[PKPushManager pushRegistry:didReceiveIncomingPushWithPayload:forType:withCompletionHandler:]`
reads `payload.dictionaryPayload["data"]`, builds a **`VKCPushPayload`** via
`+[VKCPushPayload payloadFromDictionary:]`, and calls
`+[VKCallsService handleVoIPPushPayload:toUserId:source:session:completion:]` → native CallKit +
WebRTC join + audio. (`-[VKCallsService onQueueEvent:]` is the same path VK uses when the app is
open and the call comes over the queue — that is why "VK's own call worked fine inside".)

`+[VKCPushPayload payloadFromDictionary:]` dict keys (ground truth):
`call_id, caller_info{ user_id, ... }, conversation_params, chat_info, answer_from, p2p_forbidden`.
These map 1:1 onto the queue `incoming_call` event our relay already captures
(call_id, caller_info.user_id, conversation_params, from_id→answer_from).

Native VoIP push (to the VoIP topic):
```json
{ "data": { "call_id":"<guid>",
            "caller_info":{"user_id":<from_id>,"first_name":"..","last_name":".."},
            "conversation_params":"<b64 webrtc join>",
            "answer_from":<from_id>, "p2p_forbidden":false, "to_id":<recipient uid> } }
```

**New dylib behaviour:** stop building our own CallKit (`ruas_call`); for a native payload (`data`
present) let VK's own `pushRegistry:didReceiveIncomingPush` handler run (call `gOrigIncoming`). VK
then reports the CallKit call itself (satisfies iOS's "VoIP push must report a call" rule) and joins
the real call, so audio flows. VKDump.m (FairPlay dumper) is now unnecessary — the source IPA is
already decrypted.

## Key method addresses (VKClient 8.183, base 0x100000000)
- `+[VKCallsService handleVoIPPushPayload:toUserId:source:session:completion:]` 0x1082b5970
- `+[VKCallsService doHandleVoIPPushPayload:session:completion:]` 0x1082b6028
- `-[VKCallsService onQueueEvent:]` 0x1082b61c8
- `+[VKCPushPayload payloadFromDictionary:]` 0x1082a544c
- `-[PKPushManager pushRegistry:didReceiveIncomingPushWithPayload:forType:withCompletionHandler:]` 0x108d65fc0
- `-[VKAPNSManager parseCategoryFromPayload:]` 0x1087aa778 ; `+initialize` 0x1087a9548
- `-[AppDelegate userNotificationCenter:didReceiveNotificationResponse:withCompletionHandler:]` 0x108224644
- `-[VKAPNSHandler handle]` (web fallback: url/external_url) 0x10879f940
