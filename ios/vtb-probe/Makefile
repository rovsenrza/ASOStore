SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := VTBProbe.dylib
$(OUT): VTBProbe.m
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=14.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @rpath/$(OUT) -framework Foundation -framework UIKit -o $@ VTBProbe.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
