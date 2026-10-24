#!/usr/bin/env python3
"""Fetch IPA document thumbnails from Telegram into private server staging."""

import argparse
import asyncio
import getpass
import hashlib
import json
from pathlib import Path

from telethon import TelegramClient


async def main(session: Path, manifest_path: Path, out_dir: Path) -> None:
    api_id = int(input("Telegram api_id: "))
    api_hash = getpass.getpass("Telegram api_hash: ")
    selected = json.loads(manifest_path.read_text())["selected"]
    out_dir.mkdir(parents=True, exist_ok=True)
    out_dir.chmod(0o700)
    results = {}
    async with TelegramClient(str(session.with_suffix("")), api_id, api_hash) as client:
        for start in range(0, len(selected), 50):
            batch = selected[start : start + 50]
            messages = await client.get_messages("unlimipa", ids=[entry["message_id"] for entry in batch])
            by_id = {message.id: message for message in messages if message is not None}
            for entry in batch:
                message_id = entry["message_id"]
                message = by_id.get(message_id)
                target = out_dir / f"{message_id}.jpg"
                if message is None or not message.document or not message.file or message.file.name != entry["filename"]:
                    results[str(message_id)] = {"status": "MESSAGE_MISMATCH"}
                    print(f"MISMATCH {message_id}", flush=True)
                    continue
                thumbs = [thumb for thumb in (message.document.thumbs or []) if getattr(thumb, "w", 0) >= 160]
                if not thumbs:
                    results[str(message_id)] = {"status": "NO_THUMBNAIL"}
                    print(f"NO_THUMBNAIL {message_id}", flush=True)
                    continue
                if not target.is_file() or target.stat().st_size == 0:
                    downloaded = await client.download_media(message, thumb=-1, file=str(target))
                    if downloaded is None or not target.is_file() or target.stat().st_size == 0:
                        results[str(message_id)] = {"status": "DOWNLOAD_FAILED"}
                        print(f"DOWNLOAD_FAILED {message_id}", flush=True)
                        continue
                target.chmod(0o600)
                results[str(message_id)] = {
                    "status": "DOWNLOADED",
                    "path": str(target),
                    "size_bytes": target.stat().st_size,
                    "sha256": hashlib.sha256(target.read_bytes()).hexdigest(),
                    "source_url": entry["url"],
                }
                print(f"DOWNLOADED {message_id} {target.stat().st_size}", flush=True)
            temporary = out_dir / "thumbnails.json.tmp"
            temporary.write_text(json.dumps(results, ensure_ascii=False, indent=2))
            temporary.replace(out_dir / "thumbnails.json")
    print(f"DONE downloaded={sum(row['status'] == 'DOWNLOADED' for row in results.values())} total={len(selected)}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("session", type=Path)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("out_dir", type=Path)
    args = parser.parse_args()
    asyncio.run(main(args.session, args.manifest, args.out_dir))
