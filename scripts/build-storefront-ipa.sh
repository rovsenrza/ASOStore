#!/usr/bin/env bash
# Builds the unsigned Ru App Store IPA (Release, production API) for one Apple team's variant.
# The signing runner re-signs it per device, so no certificate is needed here.
#
#   scripts/build-storefront-ipa.sh                       # com.ruappstore.app (first team)
#   BUNDLE_ID=com.ruappstore.app2 scripts/build-storefront-ipa.sh
#
# Then copy it to the server and publish it with scripts/publish-storefront-ipa.php.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUNDLE_ID="${BUNDLE_ID:-com.ruappstore.app}"
OUT="${OUT:-$ROOT/ios/build/ipa}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

xcodebuild -project "$ROOT/ios/Storefront.xcodeproj" -scheme Storefront -configuration Release \
  -sdk iphoneos -destination 'generic/platform=iOS' -derivedDataPath "$WORK/derived" \
  STOREFRONT_BUNDLE_ID="$BUNDLE_ID" CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO CODE_SIGN_IDENTITY="" \
  build -quiet

APP="$WORK/derived/Build/Products/Release-iphoneos/Storefront.app"
test "$(/usr/libexec/PlistBuddy -c 'Print CFBundleIdentifier' "$APP/Info.plist")" = "$BUNDLE_ID"
test "$(/usr/libexec/PlistBuddy -c 'Print StorefrontAPIMode' "$APP/Info.plist")" = "live"
VERSION="$(/usr/libexec/PlistBuddy -c 'Print CFBundleShortVersionString' "$APP/Info.plist")"
BUILD="$(/usr/libexec/PlistBuddy -c 'Print CFBundleVersion' "$APP/Info.plist")"

mkdir -p "$WORK/Payload" "$OUT"
cp -R "$APP" "$WORK/Payload/"
IPA="$OUT/RuAppStore-$BUNDLE_ID-$VERSION-$BUILD.ipa"
rm -f "$IPA"
(cd "$WORK" && /usr/bin/zip -qry "$IPA" Payload)
echo "$IPA ($BUNDLE_ID $VERSION ($BUILD), unsigned, sha256 $(shasum -a 256 "$IPA" | cut -d' ' -f1))"
