# OVKQuiet.dylib: silences OrwellVK's donation reminder, update check, welcome and changelog screens
# in the VK + OrwellVK mod. Inject with: scripts/inject-dylib.py IN.ipa OUT.ipa OVKQuiet.dylib
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
CFLAGS := -arch arm64 -arch arm64e -miphoneos-version-min=14.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @rpath/OVKQuiet.dylib -framework Foundation -framework UIKit

OVKQuiet.dylib: OVKQuiet.m
	$(CLANG) $(CFLAGS) -o $@ OVKQuiet.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@echo "built $@ ($$(lipo -archs $@))"

clean:
	rm -f OVKQuiet.dylib

.PHONY: clean
