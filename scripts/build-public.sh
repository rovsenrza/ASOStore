#!/usr/bin/env bash
# Publish the static portal and admin panel into Laravel's docroot so the whole
# product is served from one origin (IMPLEMENTATION_PLAN D1, D13):
#   /            front/public
#   /admin/      admin/public
#   /shared/js/  shared/js
#   /mock/       docs/api/examples   (only when MOCKS=1, the default; use MOCKS=0 for production)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCROOT="$ROOT/backend/public"
MOCKS="${MOCKS:-1}"

# Remove only what a previous build published; Laravel's own files stay.
rm -rf "$DOCROOT/css" "$DOCROOT/js" "$DOCROOT/assets" "$DOCROOT/shared" "$DOCROOT/admin" "$DOCROOT/mock"
find "$DOCROOT" -maxdepth 1 -name '*.html' -delete

cp -R "$ROOT/front/public/." "$DOCROOT/"
mkdir -p "$DOCROOT/admin" "$DOCROOT/shared"
cp -R "$ROOT/admin/public/." "$DOCROOT/admin/"
cp -R "$ROOT/shared/js" "$DOCROOT/shared/js"

if [[ "$MOCKS" == "1" ]]; then
  cp -R "$ROOT/docs/api/examples" "$DOCROOT/mock"
fi

echo "Published portal, admin and shared JS to backend/public (mocks: $MOCKS)."
