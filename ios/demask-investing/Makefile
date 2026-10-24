SDK    := iphoneos
MIN    := 16.0
CLANG  := xcrun --sdk $(SDK) clang
OUT    := DemaskInvesting.dylib
SRC    := DemaskInvesting.m fishhook.c
CFLAGS := -arch arm64 -arch arm64e -miphoneos-version-min=$(MIN) -fobjc-arc -O2 -fvisibility=hidden -Wall
LDFLAGS:= -dynamiclib -install_name @executable_path/$(OUT) -framework Foundation -framework UIKit
$(OUT): $(SRC) fishhook.h
	$(CLANG) $(CFLAGS) $(LDFLAGS) -o $@ $(SRC)
	@codesign --remove-signature $@ 2>/dev/null || true
	@echo built; lipo -archs $@
clean:; rm -f $(OUT)
.PHONY: clean
