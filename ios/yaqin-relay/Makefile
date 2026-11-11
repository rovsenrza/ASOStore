# Builds YaqinRelay.dylib — a separate relay hook for re-signed Yaqin (MAX white-label on oneme.ru).
# Does NOT touch MAXRelay. Same architecture as MAXRelay, hook targets renamed OKM*/OM* -> YAQIN*.
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
OUT   := YaqinRelay.dylib
$(OUT): YaqinRelay.m fishhook.c fishhook.h
	$(CLANG) -arch arm64 -arch arm64e -miphoneos-version-min=13.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @executable_path/$(OUT) -framework Foundation -framework CallKit -framework AVFoundation -lsqlite3 -o $@ YaqinRelay.m fishhook.c
	@codesign --remove-signature $@ 2>/dev/null || true
	@lipo -archs $@
