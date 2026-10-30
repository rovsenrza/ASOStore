# Builds one VTBUnmask variant per appbankRU "deceptium" mask. Each variant bakes the vendor
# bundle id the gate whitelists (the app's own original CFBundleIdentifier) via -DORIGINAL_BUNDLE_ID,
# and is named VTBUnmask-<bundle id>.dylib so the backend can inject the one matching the artifact.
# The injected name inside the app stays VTBUnmask.dylib (the runner copies/signs it generically).
SDK   := iphoneos
CLANG := xcrun --sdk $(SDK) clang
CFLAGS := -arch arm64 -arch arm64e -miphoneos-version-min=14.0 -fobjc-arc -O2 -fvisibility=hidden -Wall \
	  -dynamiclib -install_name @rpath/VTBUnmask.dylib -framework Foundation

# Vendor bundle ids the deceptium gate whitelists, one per masked app.
VARIANTS := com.matila.EventPulse com.sm.FlowTime

OUTS := $(foreach v,$(VARIANTS),VTBUnmask-$(v).dylib)

all: $(OUTS)

VTBUnmask-%.dylib: VTBUnmask.m
	$(CLANG) $(CFLAGS) -DORIGINAL_BUNDLE_ID=@\"$*\" -o $@ VTBUnmask.m
	@codesign --remove-signature $@ 2>/dev/null || true
	@echo "built $@ ($$(lipo -archs $@)) -> $*"

clean:
	rm -f VTBUnmask-*.dylib VTBUnmask.dylib

.PHONY: all clean
