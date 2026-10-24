#!/usr/bin/env python3
"""Cache App Store metadata for selected Telegram IPA bundle identifiers."""

import json
import pathlib
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: lookup-telegram-metadata.py selection.json metadata.json", file=sys.stderr)
        return 2
    manifest = json.loads(pathlib.Path(sys.argv[1]).read_text())
    target = pathlib.Path(sys.argv[2])
    cache = json.loads(target.read_text()) if target.exists() else {}
    for entry in manifest["selected"]:
        bundle = entry["bundle_identifier"]
        if bundle in cache:
            continue
        country = "ru" if ".ru" in bundle or any("\u0400" <= char <= "\u04ff" for char in entry["name"]) else "us"
        url = "https://itunes.apple.com/lookup?" + urllib.parse.urlencode(
            {"bundleId": bundle, "country": country, "entity": "software", "lang": "ru_ru"}
        )
        try:
            with urllib.request.urlopen(url, timeout=20) as response:
                payload = json.load(response)
            match = next((item for item in payload.get("results", []) if item.get("bundleId") == bundle and item.get("kind") == "software"), None)
            cache[bundle] = match or {}
            print(f"{entry['message_id']} {bundle} {'FOUND' if match else 'NONE'}", flush=True)
        except (urllib.error.URLError, TimeoutError, ValueError) as exc:
            print(f"{entry['message_id']} {bundle} ERROR {exc}", file=sys.stderr, flush=True)
            time.sleep(3.1)
            continue
        temporary = target.with_suffix(target.suffix + ".tmp")
        temporary.write_text(json.dumps(cache, ensure_ascii=False))
        temporary.replace(target)
        time.sleep(3.1)
    print(f"DONE looked_up={len(cache)}", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
