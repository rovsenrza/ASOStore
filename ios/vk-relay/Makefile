# Builds VKRelay.dylib, injected into re-signed VK (storefront.signing.relay_hook): it registers
# the user's VK session + push tokens with the server-side notification mirror. Run on macOS with Xcode.
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := VKRelay.dylib
$(OUT): VKRelay.m
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @executable_path/$(OUT) \
	  -framework Foundation -framework UIKit -framework PushKit -framework CallKit -framework BackgroundTasks -framework Security -o $@ VKRelay.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
