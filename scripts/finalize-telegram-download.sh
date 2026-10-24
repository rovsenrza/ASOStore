#!/usr/bin/env bash
# Run on the server alongside telegram-ipa.py download.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

while pgrep -f '[t]elegram-ipa.py download' >/dev/null; do
  sleep 60
done

python3 scripts/select-telegram-ipas.py \
  .local/telegram/unlimipa-inventory.json \
  .local/telegram/unlimipa \
  .local/telegram/selection.json

# The server needed a temporary copy of the Telegram login session. The
# original session stays on the operator's computer.
python3 - <<'PY'
from pathlib import Path
base = Path('.local/telegram')
for name in ('unlimipa.session', 'unlimipa.session.qr-failed', 'login-qr.png'):
    (base / name).unlink(missing_ok=True)
PY
