# Builds MAXFeatures.dylib, injected into re-signed MAX: forces on MAX's native (but server-gated)
# reactions and stories feature flags. Run on macOS with Xcode.
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := MAXFeatures.dylib
$(OUT): MAXFeatures.m
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @executable_path/$(OUT) \
	  -framework Foundation -framework UIKit -framework CoreGraphics -framework QuartzCore \
	  rlottie/librlottie.a -lc++ -o $@ MAXFeatures.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
