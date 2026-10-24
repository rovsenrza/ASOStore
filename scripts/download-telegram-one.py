#!/usr/bin/env python3
"""Download one Telegram IPA directly on the server with an authorised user session."""

import argparse
import asyncio
import getpass
from pathlib import Path

from telethon import TelegramClient


async def main(session: Path, message_id: int, target: Path) -> None:
    api_id = int(input("Telegram api_id: "))
    api_hash = getpass.getpass("Telegram api_hash: ")
    async with TelegramClient(str(session.with_suffix("")), api_id, api_hash) as client:
        message = await client.get_messages("unlimipa", ids=message_id)
        if message is None or not message.file or not message.file.name.lower().endswith(".ipa"):
            raise RuntimeError("The message does not contain an IPA file")
        downloaded = await client.download_media(message, file=str(target))
        if downloaded is None or target.stat().st_size != message.file.size:
            raise RuntimeError("IPA download is incomplete")
        print(f"Downloaded {target} ({target.stat().st_size} bytes)")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("session", type=Path)
    parser.add_argument("message_id", type=int)
    parser.add_argument("target", type=Path)
    args = parser.parse_args()
    asyncio.run(main(args.session, args.message_id, args.target))
