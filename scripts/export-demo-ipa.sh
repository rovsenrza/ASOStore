#!/usr/bin/env bash
# Builds the unsigned DemoApp IPA (IMPLEMENTATION_PLAN P5-OPS-01). See fixtures/DemoApp/README.md.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/fixtures/DemoApp"
OUT="${OUT:-$SRC/build}"
BUNDLE_ID="${DEMO_BUNDLE_ID:-com.example.storefront.demo}"
VERSION="${DEMO_VERSION:-1.0.0}"
BUILD="${DEMO_BUILD:-1}"
MIN_IOS="18.0"

SDK="$(xcrun --sdk iphoneos --show-sdk-path)"
SDK_VERSION="$(xcrun --sdk iphoneos --show-sdk-version)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/Payload/DemoApp.app"
mkdir -p "$APP" "$OUT"

xcrun --sdk iphoneos swiftc -parse-as-library -O \
  -target "arm64-apple-ios$MIN_IOS" -sdk "$SDK" \
  "$SRC/DemoApp.swift" -o "$APP/DemoApp"

cat > "$APP/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleDevelopmentRegion</key><string>ru</string>
  <key>CFBundleDisplayName</key><string>Demo App</string>
  <key>CFBundleExecutable</key><string>DemoApp</string>
  <key>CFBundleIdentifier</key><string>$BUNDLE_ID</string>
  <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
  <key>CFBundleName</key><string>DemoApp</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$VERSION</string>
  <key>CFBundleVersion</key><string>$BUILD</string>
  <key>CFBundleSupportedPlatforms</key><array><string>iPhoneOS</string></array>
  <key>DTPlatformName</key><string>iphoneos</string>
  <key>DTSDKName</key><string>iphoneos$SDK_VERSION</string>
  <key>DTPlatformVersion</key><string>$SDK_VERSION</string>
  <key>MinimumOSVersion</key><string>$MIN_IOS</string>
  <key>LSRequiresIPhoneOS</key><true/>
  <key>UIDeviceFamily</key><array><integer>1</integer><integer>2</integer></array>
  <key>UIRequiredDeviceCapabilities</key><array><string>arm64</string></array>
  <key>UILaunchScreen</key><dict/>
  <key>UIApplicationSceneManifest</key><dict><key>UIApplicationSupportsMultipleScenes</key><false/></dict>
</dict>
</plist>
PLIST
plutil -lint "$APP/Info.plist" >/dev/null
printf 'APPL????' > "$APP/PkgInfo"

IPA="$OUT/DemoApp.ipa"
rm -f "$IPA"
(cd "$WORK" && /usr/bin/zip -qry "$IPA" Payload)
echo "$IPA ($BUNDLE_ID $VERSION ($BUILD), unsigned, sha256 $(shasum -a 256 "$IPA" | cut -d' ' -f1))"
