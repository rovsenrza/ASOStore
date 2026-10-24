# MAX (ВКонтакте / OKMessenger) — full reverse-engineering reference

Durable map of the MAX iOS app so we can add features and fix integration gaps without re-deriving
the structure each time. Anchored on a full static analysis of the shipped binary.

## 0. Target, tooling, how to go deeper

- **Binary:** decrypted `MAX 26.35.0`, main executable `MAX`, arm64, 46 MB, cryptid 0.
  SHA-256 `423fab84c7b821fe755d39033e303d63635533d09aa7644af13672fe768dd860`.
  (Source IPA `/Users/User/Downloads/MAX-26.35.0.ipa` is the diyorde sideload capture: its load
  commands reference `diyordeMax.dylib` + `Sideload*.dylib`, but those tweaks are injected at install
  and are NOT bundled here — only `FixedApp1/2.dylib`, `libpro.dylib`, and `WebRTC.framework` are.)
- **Full ObjC inventory:** `objc-classes-full.txt` — **4433 classes / 45650 methods** with
  `selector @ imp-address`. Produced by `scratchpad/objc_dump.py` (a custom Mach-O chained-fixup +
  relative-method-list parser). Ghidra's own ObjC analyzer is unreliable here — it throws
  `Unsupported Objective C type encoding: <` on Swift generics — so this parser is the source of truth
  for the class/method map.
- **Decompile anything on demand:** a complete Ghidra 12.1.4 analysis DB exists at
  `scratchpad/max-re/ghproj/maxproj` (import + full auto-analysis, 6 GB heap). To decompile a set of
  methods, put their imp addresses (from the inventory) one-per-line in a file and run:
  ```
  GHIDRA_HEADLESS_MAXMEM=6G JAVA_HOME=/opt/homebrew/opt/openjdk@21 \
    ~/tools/ghidra_12.1.4_PUBLIC/support/analyzeHeadless scratchpad/max-re/ghproj maxproj \
    -process MAX -noanalysis -scriptPath scratchpad/ghidra_scripts \
    -postScript DecompList.java <addr-file> <out.c>
  ```
  (Re-create the DB with `-import MAX -overwrite` and NO `-analysisTimeoutPerFile` flag — `0` means
  "0 seconds" = immediate timeout, which silently skips analysis.)
- **Already decompiled (in this folder):** `audio-call-decomp.c` (OKMAudioSession + CallKitController +
  VoIPPushRegistry) and `callchain-decomp.c` (native call-audio activation + VoIP-push dispatch).
- Runtime (Frida) is the right tool for what static analysis can't settle (e.g. which audio route is
  live during a backgrounded call); not yet done.

## 1. Naming & module layout

- `OKM*` — OK-Messenger ObjC core (chat, audio, config, network, push).
- `OM*` — newer messenger layer (`OMCalls`, `OMCallsIntegration`, `OMStoriesEditor`, `OMWebApp*`, `OMLogger`).
- `OV*` — OK video / media / WebRTC (`OVCalls`, `OVWebRTC`, `OVKit`).
- `OKTT*` — input bar / rich text / trimmer UI.
- Swift classes are mangled `_TtC<len><Module><len><Class>` (e.g. `_TtC7OMCalls17CallKitController`).
- Storage: **YapDatabase** (SQLite-backed) for messages/history. Networking: `OKMPNWClient` over
  `OKMPTCPConnection`; there is also a `WebTransport`/HTTP3 Swift stack.

## 2. Domain → key classes (entry points for new features)

| Domain | Key classes (imp addresses in objc-classes-full.txt) |
|---|---|
| **Calls (CallKit)** | `OMCalls.CallKitController`, `OMCalls.CallSessionManager`, `OMCalls.CallAudioRoutePicker`, `OMCallsIntegration.CallAudioSessionHandler`, `OVCalls.CallSession`, `OVWebRTC.*AudioDeviceModule` |
| **Call/media audio** | `OKMAudioSession` (session state machine), `OKMAudioRouter` (music/media player, NOT calls), WebRTC.framework `RTCAudioSession` |
| **VoIP / push** | `App.VoIPPushRegistry`, `OKMPushServiceRegister` |
| **Transport / protocol** | `OKMPNWClient` (`_handleIncomingPacket:` dispatches opcodes), `OKMPPacket`, `OKMPTCPConnection`, `OKMConnectionProbe` |
| **Session / auth** | `OKMMessengerCredentials`, PassePartout `PSPTAuthorizedUserSession` (access/refresh token), `OVSKeychain`, `OMCalls.CallSessionManager` |
| **Config / feature flags** | `OKMKVConfigService` (the server KV config; read via `boolValueFor:default:` string-keyed), `OKMKVConfig*` option structs, `OVCConfigExperimental` |
| **Stories** | `OMStoriesEditor.*`, `OMStoriesViewer.*` |
| **Comments (channel posts)** | `OKMMessageCommentsInfo`, `ChatHistoryUI.ChannelPostCommentsView` |
| **Web apps / mini apps** | `OMWebAppService`, `OMWebAppBridge.*`, `WebAppControllerFactory`, `OMWebAppStorage` |
| **Reactions** | (see memory [[max-reaction-picker]]) OKMChatService addReaction path |

## 3. Call + audio subsystem (the "call opens on speaker" bug)

### 3.1 Native MAX routes a call to the EARPIECE by design
From `audio-call-decomp.c` (`OKMAudioSession`, the single owner of the call audio session):

- **`_sessionModeForOKMCategory:`** — OKM **category 5 ⇒ `AVAudioSessionModeVoiceChat`** (earpiece,
  echo-cancelled); every other category ⇒ `AVAudioSessionModeDefault`.
- **`_sessionCategoryOptionsForOKMCategory:`** — returns `1` only for category 6; category 5 (call)
  ⇒ `0` (so **no `defaultToSpeaker` option** on a call).
- **`setupAudioSessionWithWebRTCParams:`** — builds `RTCAudioSessionConfiguration` with
  **`AVAudioSessionCategoryPlayAndRecord` + `AVAudioSessionModeVoiceChat`** (earpiece).
- **`_actualizeOverrideToSpeakerIfNeeded`** — only overrides to speaker when the current category is
  **4** (media playback), checking `[BuiltInReceiver, BuiltInSpeaker]`; it does NOT speaker-ify calls.
- **`_configureSessionWithCategory:enableSpeaker:completion:`** — for a call (category 5, no explicit
  `enableSpeaker`) speaker = **0 (earpiece)**. `enableSpeaker` is only honoured when passed explicitly
  (`setCallActiveWithPlayer:enableSpeaker:` wraps it as an NSNumber).
- **`setShouldOverrideToEarForVoicePlayback:`** forces earpiece for voice playback (cat 4 case).
- `handleRouteChangeNotification:` + `handleInterruptionNotification:` keep the route coherent and are
  owned by `OKMAudioSession`.

### 3.2 How a call gets to that code
`CallKitController` is the `CXProviderDelegate`:
`providerDidBegin:` / `performStartCallAction:` / `performAnswerCallAction:` (`FUN_100bb0110`) /
`didActivateAudioSession:` (`FUN_100bb0950`) / `didDeactivateAudioSession:`. On activation it drives
OKMAudioSession into the VoiceChat call state.

`VoIPPushRegistry.pushRegistry:didReceiveIncomingPushWithPayload:` (`FUN_10001b3dc`): if the app's
call delegate is live it calls `didReceiveVoIPPushWithPushPayload:`; if the app is **not booted**
(backgrounded cold launch) it **queues the push into `pendingVoIPPushes` and calls
`triggerAppBootstrap`**, then replays once the app (and its OMCalls stack) is up. Either way the native
OMCalls → CallKit → OKMAudioSession(VoiceChat) path is what ultimately configures audio.

### 3.3 Our integration and why audio still lands on speaker
We inject `diyordeMax.dylib` (in the `MAXFeatures.dylib` slot) for its UI features, but deliver calls
through OUR relay VoIP push. `scripts/patch-diyordemax-calls.py` overwrites diyorde's
`_max_call_notify_install` prologue with `ret`, which **restores MAX's native call handling** so our
relay's native-format VoIP payload is accepted exactly as on 26.17 (see [[max-calls-diyordemax-call-hook]]).
So calls DO run the native OMCalls/OKMAudioSession path — native audio is earpiece.

Therefore the reported bug — **backgrounded incoming call → audio on speaker, the CallKit speaker
toggle doesn't change it, and opening MAX + toggling kills audio** — is NOT a wrong code path. The
leading hypothesis is **ownership/timing on a backgrounded activation**: CallKit activates the audio
session while the app is still in the background, OKMAudioSession's route actualization
(`_actualizeOverrideToSpeakerIfNeeded`, route-change handling) needs the OMCalls UI/app context that
only runs once MAX is foregrounded, so the first route is wrong and later the in-app toggle reconfigures
an already-active session (`_configureSessionWithCategory:` / setActive) and tears it down.

**To close it (needs on-device Frida, not static-only):**
1. Frida-trace `OKMAudioSession` on the device during a backgrounded call: log
   `setCallActiveWithPlayer:enableSpeaker:`, `_configureSessionWithCategory:enableSpeaker:`,
   `_setDefaultAudioRouteToSpeaker:`, `_actualizeOverrideToSpeakerIfNeeded`, `setCurrentCategory:`,
   and `AVAudioSession currentRoute` — confirm whether category 5/VoiceChat is actually set and what
   `enableSpeaker` value is used.
2. Also confirm our relay call payload is not flagging an audio call as video: `mxrelay.py` opcode-137
   sends `isVideo/video = (ctype=="VIDEO")` where `ctype = field_str(body,"type") or "AUDIO"`. A false
   VIDEO flag would make MAX set up a video call (speaker). Verify the "type" field detection.
3. If it is the backgrounded-activation case, the fix is to force OKMAudioSession into the call state
   on activation: from our injected dylib, on `provider:didActivateAudioSession:` (or when our call
   push is handled), call `[OKMAudioSession.sharedInstance setCallActiveWithPlayer:… enableSpeaker:NO]`
   / `setShouldOverrideToEarForVoicePlayback:YES`, so a single owner (OKMAudioSession) holds the
   session in VoiceChat/earpiece whether or not the UI has come up.

## 4. VoIP push / notifications
`VoIPPushRegistry` (§3.2) for calls; `OKMPushServiceRegister` registers the APNs/push service. Message
pushes are delivered by our relay (`ios/max-relay` + `/opt/mxrelay`), see [[max-background-push-re]],
[[max-notification-entitlement-block-20261021]], [[max-per-team-push-certs]]. <!-- deepen on demand -->

## 5. Transport / wire protocol
`OKMPNWClient` over `OKMPTCPConnection`; `_handleIncomingPacket:` dispatches opcodes (128 = message,
137 = call, per our relay). Packets are `OKMPPacket`. Prior opcode map (reactions 178/179, comments
64/161/75, stories 213/212, animoji 28/26) is in [[max-web-protocol-re]] / [[max-comments-stories-re]].
<!-- To extend: decompile OKMPNWClient _handleIncomingPacket: and the packet encoders. -->

## 6. Feature flags / server config gating
`OKMKVConfigService` is the key-value config store; features are gated via `boolValueFor:default:`
(string keys, e.g. comments = `channel-post-comments-enabled`, see [[max-stories-comments-flags]]).
To force a feature on in a re-signed build, hook the getter and return the desired default for the
specific key (string-matched), as done for comments/stories. `OKMKVConfig*` option structs hold typed
config (e.g. `OKMKVConfigVideoMessagesUploadDetectionOptions`). <!-- enumerate keys on demand -->

## 7. Session / auth / keychain
Token lives in PassePartout `PSPTAuthorizedUserSession` (access + refresh); `OKMMessengerCredentials`
and `OVSKeychain` wrap credential storage. (Our relay captures the session server-side; VK's analogous
keychain service is documented in the VK work.) <!-- deepen on demand -->

## 8. Feature areas for future work
- **Stories:** `OMStoriesEditor.*` (compose) + `OMStoriesViewer.*` (view). 
- **Comments:** `OKMMessageCommentsInfo`, `ChannelPostCommentsView` (channel-post threads).
- **Web/mini apps:** `OMWebAppService` + `OMWebAppBridge` (JS bridge), `WebAppControllerFactory`.
- **Reactions:** long-press picker + `OKMChatService addReaction` ([[max-reaction-picker]]).

## 9. Our integration surface & known gaps
- Inject set: `MAXRelay.dylib` (session→relay), `MAXFeatures.dylib` (= patched `diyordeMax.dylib`,
  UI features), `MAXGlass.dylib` (Liquid Glass tab bar), compat shim.
- Calls/notifications via `/opt/mxrelay` + per-team push/voip certs.
- **Open gap (this investigation):** call audio route on a backgrounded incoming call (speaker,
  uncontrollable; foregrounding kills audio) — root-caused to OKMAudioSession call-state ownership on
  background activation; fix requires the on-device Frida confirmation in §3.3 then a small
  activation-time hook. Other relay items tracked in the MAX memories.
