#!/usr/bin/env python3
"""Select the newest valid IPA for each bundle ID from a Telegram inventory.

The inventory is ordered newest first. Files are expected as <message_id>-unlimipa.ipa
under the download directory. This command reads archives without executing them.
"""

import argparse
import json
import plistlib
import re
import zipfile
from pathlib import Path


INFO_PATTERN = re.compile(r"^Payload/[^/]+\.app/Info\.plist$")


def inspect(path):
    with zipfile.ZipFile(path) as archive:
        info_files = [entry for entry in archive.infolist() if INFO_PATTERN.match(entry.filename)]
        if len(info_files) != 1:
            raise ValueError("Expected one root app Info.plist")
        if info_files[0].file_size > 4 * 1024 * 1024:
            raise ValueError("Info.plist exceeds 4 MiB")
        info = plistlib.loads(archive.read(info_files[0]))
    bundle = info.get("CFBundleIdentifier")
    version = info.get("CFBundleShortVersionString")
    build = info.get("CFBundleVersion")
    if not isinstance(bundle, str) or not bundle or not isinstance(version, str) or not version:
        raise ValueError("Missing bundle ID or version")
    name = info.get("CFBundleDisplayName") or info.get("CFBundleName") or path.stem
    return {"bundle_identifier": bundle, "version": version, "build_number": str(build or ""), "name": str(name)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("inventory", type=Path)
    parser.add_argument("downloads", type=Path)
    parser.add_argument("output", type=Path)
    args = parser.parse_args()

    selected = {}
    skipped = []
    missing = []
    for item in json.loads(args.inventory.read_text()):
        message_id = item["message_id"]
        if item["excluded_diyorde"]:
            skipped.append({"message_id": message_id, "reason": "DIYORDE_FILENAME"})
            continue
        path = args.downloads / "{}-unlimipa.ipa".format(message_id)
        if not path.exists():
            missing.append(message_id)
            continue
        if path.stat().st_size != item["size_bytes"]:
            skipped.append({"message_id": message_id, "reason": "SIZE_MISMATCH"})
            continue
        try:
            metadata = inspect(path)
        except (OSError, ValueError, zipfile.BadZipFile, plistlib.InvalidFileException) as exc:
            skipped.append({"message_id": message_id, "reason": "INVALID_IPA", "detail": str(exc)})
            continue
        bundle_key = metadata["bundle_identifier"].casefold()
        if "com.diyorde" in bundle_key:
            skipped.append({"message_id": message_id, "reason": "DIYORDE_BUNDLE_ID"})
        elif bundle_key in selected:
            skipped.append({"message_id": message_id, "reason": "OLDER_BUNDLE", "newer_message_id": selected[bundle_key]["message_id"]})
        else:
            selected[bundle_key] = {**item, **metadata, "path": str(path)}

    result = {"selected": list(selected.values()), "skipped": skipped, "missing_message_ids": missing}
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
    print("{} unique bundles; {} skipped; {} files missing".format(len(selected), len(skipped), len(missing)))


if __name__ == "__main__":
    main()
