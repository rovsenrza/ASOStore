# Builds RuStoreCompat.dylib, the launch-compatibility shim injected into re-signed
# apps (see README.md). Run on macOS with Xcode; the output is a fat arm64 + arm64e
# iOS dylib checked into the repo and signed per device by the runner's zsign.
SDK      := iphoneos
MIN      := 13.0
CLANG    := xcrun --sdk $(SDK) clang
OUT      := RuStoreCompat.dylib
SRC      := RuStoreCompat.m fishhook.c
CFLAGS   := -arch arm64 -arch arm64e -miphoneos-version-min=$(MIN) -fobjc-arc -O2 \
            -fvisibility=hidden -Wall
LDFLAGS  := -dynamiclib -install_name @executable_path/$(OUT) \
            -framework Foundation -framework Security

$(OUT): $(SRC) fishhook.h
	$(CLANG) $(CFLAGS) $(LDFLAGS) -o $@ $(SRC)
	@codesign --remove-signature $@ 2>/dev/null || true
	@echo "built $@:" && lipo -archs $@ && otool -l $@ | grep -A2 LC_ID_DYLIB | head -3

clean:
	rm -f $(OUT)

.PHONY: clean
