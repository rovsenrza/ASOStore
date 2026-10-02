# RuStoreCompat — launch-compatibility shim

When we re-sign a customer-provided IPA for one device, the app keeps its own code but gets a
new identity: bundle ID `com.ruappstore.tg…`, one App Group `group.com.ruappstore.tg…`, and
keychain groups under our Apple team. The app's own code still asks iOS for the vendor's
original App Group and team-prefixed keychain groups — which this signature does not own — so
iOS returns `nil` / `errSecMissingEntitlement` and the app terminates right after its launch
screen (e.g. Yandex Music, Kuper).

This small dylib, injected at signing time, repairs three lookups so the app uses the identity
it was actually granted, read from its own entitlements at launch:

- `-[NSFileManager containerURLForSecurityApplicationGroupIdentifier:]` — a missing group's
  container falls back to the one group we hold.
- `-[NSUserDefaults initWithSuiteName:]` — a shared-defaults suite named after a missing group
  maps to ours.
- `SecItemAdd/CopyMatching/Update/Delete` (via fishhook) — a foreign keychain access group is
  dropped so the item uses our default group.

It only ever falls back: when the real call already works (the app's own sideload fix handled
it, or the group is genuinely ours) it changes nothing. No ads, no network, no servers. This is
the same mechanism the supplied IPAs' own mods used (the removed YMNight did exactly this);
building our own keeps it ad-free and independent of any source's modifications.

## Build

Run on macOS with Xcode (`make`). Output: `RuStoreCompat.dylib`, a fat **arm64 + arm64e** iOS
dylib, committed to the repo. The backend (`CompatShim`) reads it and sends it in the signing
lease for apps that declare App Groups or keychain sharing; the runner's zsign copies it into the
app, adds the load command and signs it per device. Rebuild and recommit after changing the source.

`fishhook.c`/`.h` are vendored from https://github.com/facebook/fishhook (BSD).

## Limitation

App↔extension keychain sharing under the vendor's team prefix is not preserved (the access group
is dropped, not remapped), and the shim is injected into the main app only, not its extensions.
That is enough for the app to launch and for its own login/session to work; it is not a guarantee
that every background extension feature works. No iPhone launch is performed by the build.
