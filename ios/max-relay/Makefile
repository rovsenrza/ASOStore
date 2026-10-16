# Builds MAXRelay.dylib, injected into re-signed MAX (storefront.signing.relay_hook): it
# registers the user's session with the server-side notification mirror. Run on macOS with Xcode.
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := MAXRelay.dylib
$(OUT): MAXRelay.m fishhook.c fishhook.h
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @executable_path/$(OUT) -framework Foundation -lsqlite3 -o $@ MAXRelay.m fishhook.c
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
