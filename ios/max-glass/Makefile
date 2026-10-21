SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := MAXGlass.dylib
$(OUT): MAXGlass.m
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @executable_path/$(OUT) -framework Foundation -framework UIKit -framework CoreGraphics -framework QuartzCore -o $@ MAXGlass.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
