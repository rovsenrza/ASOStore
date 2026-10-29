SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := MAXShareBridge.dylib
$(OUT): MAXShareBridge.m
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @rpath/$(OUT) -framework Foundation -framework UIKit -framework Security $(CFLAGS) -o $@ MAXShareBridge.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
