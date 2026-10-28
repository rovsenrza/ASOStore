# Builds TGRelay.dylib, injected into re-signed Telegram (storefront.signing.relay_hook.tg_*): it
# forces every MTProto connection through our fake-TLS MTProxy so the app works on Russian internet
# without a VPN and without the customer changing any setting, and writes an internal diagnostics
# log. Run on macOS with Xcode.
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := TGRelay.dylib
FRAMEWORKS := -framework Foundation -framework UIKit
CFLAGS     := -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall

$(OUT): TGRelay.m
	$(CLANG) $(CFLAGS) -dynamiclib -install_name @executable_path/$(OUT) $(FRAMEWORKS) -o $@ TGRelay.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@

clean:
	rm -f $(OUT)
