#!/usr/bin/env python3
"""Inventory and download IPA documents from a Telegram channel using a user session.

Install scripts/requirements-telegram-ipa.txt, then run inventory --qr.
The session and downloaded data stay under the ignored .local/telegram directory.
"""

import argparse
import asyncio
import getpass
import hashlib
import json
import os
import re
import shlex
from pathlib import Path

import qrcode
from telethon import TelegramClient
from telethon.errors import SessionPasswordNeededError


ROOT = Path(__file__).resolve().parent.parent
PRIVATE_DIR = ROOT / ".local" / "telegram"
CHANNEL = "unlimipa"


def credentials():
    api_id = os.environ.get("TELEGRAM_API_ID") or input("Telegram api_id: ").strip()
    api_hash = os.environ.get("TELEGRAM_API_HASH") or getpass.getpass("Telegram api_hash: ").strip()
    if not api_id.isdecimal() or not api_hash:
        raise SystemExit("A valid api_id and api_hash are required.")
    return int(api_id), api_hash


def ipa_name(message):
    if not message.document or not message.file:
        return None
    name = message.file.name
    if name and name.lower().endswith(".ipa"):
        return name
    return None


async def authorize(client, qr_mode):
    await client.connect()
    if await client.is_user_authorized():
        return
    if not qr_mode:
        await client.start(
            phone=lambda: input("Telegram phone number: ").strip(),
            code_callback=lambda: input("Telegram login code: ").strip(),
            password=lambda: getpass.getpass("Telegram 2FA password (if enabled): "),
        )
        return
    qr = await client.qr_login()
    qr_path = PRIVATE_DIR / "login-qr.png"
    try:
        while not await client.is_user_authorized():
            code = qrcode.QRCode(border=2)
            code.add_data(qr.url)
            code.make(fit=True)
            code.make_image().save(qr_path)
            qr_path.chmod(0o600)
            print("Scan {} in Telegram: Settings > Devices > Scan QR".format(qr_path), flush=True)
            code.print_ascii(invert=True)
            try:
                await qr.wait()
            except asyncio.TimeoutError:
                await qr.recreate()
                continue
            except SessionPasswordNeededError:
                print("Enter your existing Telegram Two-Step Verification password; no new code will be sent.")
                await client.sign_in(password=getpass.getpass("Telegram 2FA password: "))
    finally:
        qr_path.unlink(missing_ok=True)


async def remote_command(host, command):
    process = await asyncio.create_subprocess_exec(
        "ssh", "-o", "BatchMode=yes", host, command,
        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
    )
    stdout, stderr = await process.communicate()
    if process.returncode:
        raise RuntimeError("Remote command failed: {}".format(stderr.decode(errors="replace")))
    return stdout.decode().strip()


async def stream_file(client, message, host, remote_dir):
    target = "{}/{}.ipa".format(remote_dir, message.id)
    part = target + ".part"
    size = message.file.size
    existing = await remote_command(host, "stat -c %s {} 2>/dev/null || true".format(shlex.quote(target)))
    if existing == str(size):
        return "already on server"

    command = "sudo -u storefront sh -c {} sh {}".format(
        shlex.quote('umask 077; cat > "$1"'), shlex.quote(part)
    )
    process = await asyncio.create_subprocess_exec(
        "ssh", "-o", "BatchMode=yes", host, command,
        stdin=asyncio.subprocess.PIPE, stdout=asyncio.subprocess.DEVNULL,
        stderr=asyncio.subprocess.PIPE,
    )
    digest = hashlib.sha256()
    transferred = 0
    try:
        async for chunk in client.iter_download(message.document, request_size=512 * 1024, chunk_size=512 * 1024):
            digest.update(chunk)
            transferred += len(chunk)
            process.stdin.write(chunk)
            await process.stdin.drain()
    finally:
        process.stdin.close()
    stderr = (await process.stderr.read()).decode(errors="replace")
    if await process.wait() or transferred != size:
        raise RuntimeError("Transfer failed for message {}: {}".format(message.id, stderr))
    remote_hash = await remote_command(host, "sha256sum {}".format(shlex.quote(part)))
    if remote_hash.split()[0] != digest.hexdigest():
        raise RuntimeError("SHA-256 mismatch for message {}".format(message.id))
    await remote_command(host, "sudo -u storefront mv -- {} {}".format(shlex.quote(part), shlex.quote(target)))
    return digest.hexdigest()


async def stream_inventory(client, host, remote_dir, message_id, max_files):
    if not re.fullmatch(r"[A-Za-z0-9_.@-]+", host) or not re.fullmatch(r"/[A-Za-z0-9_./-]+", remote_dir):
        raise SystemExit("Invalid SSH host or remote directory")
    await remote_command(host, "sudo -u storefront mkdir -p -- {}".format(shlex.quote(remote_dir)))
    inventory = json.loads((PRIVATE_DIR / "{}-inventory.json".format(CHANNEL)).read_text())
    state_path = PRIVATE_DIR / "{}-stream-state.json".format(CHANNEL)
    state = json.loads(state_path.read_text()) if state_path.exists() else {}
    count = 0
    for item in inventory:
        if item["excluded_diyorde"] or (message_id and item["message_id"] != message_id):
            continue
        if str(item["message_id"]) in state:
            continue
        message = await client.get_messages(CHANNEL, ids=item["message_id"])
        if message is None or ipa_name(message) != item["filename"]:
            print("Skipped changed or deleted message {}".format(item["message_id"]))
            continue
        print("Streaming {}: {} ({:,} bytes)".format(message.id, item["filename"], item["size_bytes"]), flush=True)
        result = await stream_file(client, message, host, remote_dir)
        state[str(message.id)] = result
        state_path.write_text(json.dumps(state, indent=2) + "\n")
        count += 1
        if max_files and count >= max_files:
            break
    print("Streamed {} files in this run; {} complete in state.".format(count, len(state)))


async def download_inventory(client, max_files, concurrency):
    manifest = PRIVATE_DIR / "{}-inventory.json".format(CHANNEL)
    items = [item for item in json.loads(manifest.read_text()) if not item["excluded_diyorde"]]
    target_dir = PRIVATE_DIR / CHANNEL
    target_dir.mkdir(exist_ok=True)
    pending = [item for item in items if not (
        (target_dir / "{}-{}.ipa".format(item["message_id"], CHANNEL)).is_file()
        and (target_dir / "{}-{}.ipa".format(item["message_id"], CHANNEL)).stat().st_size == item["size_bytes"]
    )]
    if max_files:
        pending = pending[:max_files]
    semaphore = asyncio.Semaphore(concurrency)

    async def download_one(item):
        async with semaphore:
            message = await client.get_messages(CHANNEL, ids=item["message_id"])
            if message is None or ipa_name(message) != item["filename"]:
                print("Skipped changed or deleted message {}".format(item["message_id"]), flush=True)
                return
            target = target_dir / "{}-{}.ipa".format(item["message_id"], CHANNEL)
            part = target.with_name(target.stem + ".partial.ipa")
            part.unlink(missing_ok=True)
            print("Downloading {} ({:,} bytes)".format(item["filename"], item["size_bytes"]), flush=True)
            downloaded = await client.download_media(message, file=str(part))
            if not downloaded or Path(downloaded).stat().st_size != item["size_bytes"]:
                raise RuntimeError("Incomplete download for message {}".format(item["message_id"]))
            Path(downloaded).replace(target)

    await asyncio.gather(*(download_one(item) for item in pending))
    complete = sum((target_dir / "{}-{}.ipa".format(item["message_id"], CHANNEL)).is_file() for item in items)
    print("Downloads: {}; {} of {} complete".format(target_dir, complete, len(items)))


async def run(command, qr_mode, host, remote_dir, message_id, max_files, concurrency):
    PRIVATE_DIR.mkdir(parents=True, exist_ok=True)
    PRIVATE_DIR.chmod(0o700)
    api_id, api_hash = credentials()
    client = TelegramClient(str(PRIVATE_DIR / CHANNEL), api_id, api_hash)
    await authorize(client, qr_mode)
    try:
        if command == "inventory":
            items = []
            async for message in client.iter_messages(CHANNEL):
                name = ipa_name(message)
                if name is None:
                    continue
                items.append({
                    "message_id": message.id,
                    "date": message.date.isoformat(),
                    "filename": name,
                    "size_bytes": message.file.size,
                    "excluded_diyorde": "com.diyorde" in name.lower(),
                    "url": "https://t.me/{}/{}".format(CHANNEL, message.id),
                })
            manifest = PRIVATE_DIR / "{}-inventory.json".format(CHANNEL)
            manifest.write_text(json.dumps(items, ensure_ascii=False, indent=2) + "\n")
            print("{} IPA messages; {} excluded by filename; inventory: {}".format(
                len(items), sum(item["excluded_diyorde"] for item in items), manifest
            ))
        elif command == "stream":
            if not host or not remote_dir:
                raise SystemExit("stream requires --host and --remote-dir")
            await stream_inventory(client, host, remote_dir, message_id, max_files)
        else:
            await download_inventory(client, max_files, concurrency)
    finally:
        await client.disconnect()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["inventory", "download", "stream"])
    parser.add_argument("--qr", action="store_true", help="Log in by scanning a QR code in Telegram")
    parser.add_argument("--host", help="SSH user@host for server streaming")
    parser.add_argument("--remote-dir", help="Server staging directory")
    parser.add_argument("--message-id", type=int, help="Stream one Telegram message for verification")
    parser.add_argument("--max-files", type=int, help="Stop streaming after this many files")
    parser.add_argument("--concurrency", type=int, choices=range(1, 9), default=4, metavar="1-8", help="Simultaneous IPA downloads (default: 4)")
    args = parser.parse_args()
    asyncio.run(run(args.command, args.qr, args.host, args.remote_dir, args.message_id, args.max_files, args.concurrency))
