# Builds VKRelay.dylib, injected into re-signed VK (storefront.signing.relay_hook): it registers
# the user's VK session + push tokens with the server-side notification mirror. Run on macOS with Xcode.
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := VKRelay.dylib
FRAMEWORKS := -framework Foundation -framework UIKit -framework PushKit -framework CallKit -framework BackgroundTasks -framework Security -framework AVFoundation -framework LocalAuthentication
CFLAGS     := -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall

FACEID := VKRelay-faceid.dylib

$(OUT): VKRelay.m
	$(CLANG) $(CFLAGS) -dynamiclib -install_name @executable_path/$(OUT) $(FRAMEWORKS) -o $@ VKRelay.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@

# The general (non-test) build: only the launch-crash and Face-ID-loop fixes compiled in, none of
# the notification/call relay (-DVK_FACEID_ONLY omits it). Keeps the same install_name as the full
# dylib so the runner can inject either one unchanged. Routed per device in CompatShim: the two
# test UDIDs get the full VKRelay.dylib, every other customer gets this.
faceid: VKRelay.m
	$(CLANG) $(CFLAGS) -DVK_FACEID_ONLY -dynamiclib -install_name @executable_path/$(OUT) $(FRAMEWORKS) -o $(FACEID) VKRelay.m
	@codesign --remove-signature $(FACEID) 2>/dev/null || true
	@lipo -archs $(FACEID)

all: $(OUT) faceid

# One-off: build VKRelay.dylib WITH the FairPlay dumper compiled in (VKDump.m, gated to the test
# device). Deploy this to the webroot in place of the normal dylib for a single VK sign, pull the
# dump off the device, then restore the normal dylib. The production `make` target never includes it.
dump: VKRelay.m VKDump.m
	$(CLANG) $(CFLAGS) -DVKDUMP -dynamiclib -install_name @executable_path/$(OUT) $(FRAMEWORKS) -o $(OUT) VKRelay.m VKDump.m
	@codesign --remove-signature $(OUT) 2>/dev/null || true
	@lipo -archs $(OUT)
