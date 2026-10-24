#!/usr/bin/env python3
"""Give a disguised IPA the home-screen name and icon of the app it really is.

Banks re-released under unrelated App Store names (e.g. "Любовь и гуси" for
Россельхозбанк) install with the disguise's name and icon. This writes a copy
whose Info.plist and every localized InfoPlist.strings show NAME, and whose
primary icon comes from PNG files rendered from ICON:

    python3 scripts/rebrand-ipa.py SOURCE.ipa OUT.ipa --name "РСХБ" --icon icon.png

iOS takes the icon from the asset catalog only while CFBundleIconName is set,
so that key is dropped (with the alternate icons, which live only in the
catalog) and CFBundleIconFiles points at the new files. Bundle ID, version and
code are untouched; every other archive record is copied byte for byte. The
copy must be signed again (the signing runner does this). Needs Pillow.
"""

import argparse
import copy
import io
import plistlib
import re
import sys
import zipfile
from pathlib import Path

ICON_BASE = "RuStoreIcon"
# (file name suffix, pixels): iPhone 60pt @2x/@3x, iPad 76pt @2x, iPad Pro 83.5pt @2x.
ICON_FILES = (("60x60@2x.png", 120), ("60x60@3x.png", 180), ("76x76@2x~ipad.png", 152), ("83.5x83.5@2x~ipad.png", 167))
IPHONE_ICONS = [ICON_BASE + "60x60"]
IPAD_ICONS = [ICON_BASE + "60x60", ICON_BASE + "76x76", ICON_BASE + "83.5x83.5"]


def render_icons(path):
    from PIL import Image

    source = Image.open(path)
    if source.width != source.height or source.width < 180:
        sys.exit(f"icon must be square and at least 180 px, got {source.width}x{source.height}")
    source = source.convert("RGBA")
    # Home-screen icons must be opaque: transparent pixels show as black.
    background = Image.new("RGBA", source.size, source.getpixel((0, 0))[:3] + (255,))
    flat = Image.alpha_composite(background, source).convert("RGB")
    rendered = {}
    for suffix, pixels in ICON_FILES:
        buffer = io.BytesIO()
        flat.resize((pixels, pixels), Image.LANCZOS).save(buffer, "PNG", optimize=True)
        rendered[ICON_BASE + suffix] = buffer.getvalue()
    return rendered


def point_icons(info):
    for key, files in (("CFBundleIcons", IPHONE_ICONS), ("CFBundleIcons~ipad", IPAD_ICONS)):
        icons = info.get(key) or {}
        icons.pop("CFBundleAlternateIcons", None)
        icons["CFBundlePrimaryIcon"] = {"CFBundleIconFiles": files}
        info[key] = icons
    info.pop("CFBundleIconName", None)
    info.pop("CFBundleIconFile", None)
    info["CFBundleIconFiles"] = IPHONE_ICONS


def rewrite(source, destination, replacements, additions):
    # Same record copy as tools/ipa-cleaner: untouched local records verbatim,
    # changed ones recompressed, new ones appended after the app's files.
    with zipfile.ZipFile(source) as old, Path(source).open("rb") as raw, zipfile.ZipFile(destination, "w", allowZip64=True) as new:
        entries = sorted(old.infolist(), key=lambda item: item.header_offset)
        for index, entry in enumerate(entries):
            if entry.filename in replacements:
                new.writestr(copy.copy(entry), replacements[entry.filename], compresslevel=6)
                continue
            finish = entries[index + 1].header_offset if index + 1 < len(entries) else old.start_dir
            raw.seek(entry.header_offset)
            new.fp.seek(new.start_dir)
            item = copy.copy(entry)
            item.header_offset = new.fp.tell()
            left = finish - entry.header_offset
            if left < 0:
                sys.exit("invalid ZIP record order")
            while left:
                chunk = raw.read(min(left, 1024 * 1024))
                if not chunk:
                    sys.exit("truncated ZIP record")
                new.fp.write(chunk)
                left -= len(chunk)
            new.start_dir = new.fp.tell()
            new.filelist.append(item)
            new.NameToInfo[item.filename] = item
            new._didModify = True
        for name, data in additions.items():
            item = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            item.external_attr = 0o100644 << 16
            item.compress_type = zipfile.ZIP_DEFLATED
            new.writestr(item, data, compresslevel=6)
        new.comment = old.comment


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("source")
    parser.add_argument("destination")
    parser.add_argument("--name", required=True, help="home-screen name")
    parser.add_argument("--icon", required=True, help="square PNG/WebP/JPEG, at least 180 px")
    args = parser.parse_args()
    if Path(args.source).resolve() == Path(args.destination).resolve():
        sys.exit("destination must differ from source")

    with zipfile.ZipFile(args.source) as archive:
        names = archive.namelist()
        roots = sorted({m.group(1) for m in map(re.compile(r"^(Payload/[^/]+\.app/)").match, names) if m})
        if len(roots) != 1:
            sys.exit(f"expected one app bundle, found {roots}")
        root = roots[0]
        replacements = {}
        info = plistlib.loads(archive.read(root + "Info.plist"))
        before = {key: info.get(key) for key in ("CFBundleIdentifier", "CFBundleShortVersionString", "CFBundleVersion")}
        info["CFBundleDisplayName"] = args.name
        point_icons(info)
        replacements[root + "Info.plist"] = plistlib.dumps(info, fmt=plistlib.FMT_BINARY)
        localized = [n for n in names if re.fullmatch(re.escape(root) + r"[^/]+\.lproj/InfoPlist\.strings", n)]
        for name in localized:
            try:
                strings = plistlib.loads(archive.read(name))  # compiled .strings are binary plists
            except plistlib.InvalidFileException:
                sys.exit(f"{name} is a text .strings file; convert it with plutil first")
            if "CFBundleDisplayName" in strings or "CFBundleName" in strings:
                strings["CFBundleDisplayName"] = args.name
                replacements[name] = plistlib.dumps(strings, fmt=plistlib.FMT_BINARY)
        additions = {root + name: data for name, data in render_icons(args.icon).items()}
        clash = [name for name in additions if name in names]
        if clash:
            sys.exit(f"already rebranded? {clash}")

    rewrite(args.source, args.destination, replacements, additions)

    # Read the copy back: identity unchanged, new values present, every other record identical.
    with zipfile.ZipFile(args.source) as old, zipfile.ZipFile(args.destination) as new:
        if new.testzip() is not None:
            sys.exit("written copy fails its CRC check")
        info = plistlib.loads(new.read(root + "Info.plist"))
        if {key: info.get(key) for key in before} != before or info["CFBundleDisplayName"] != args.name:
            sys.exit("Info.plist identity changed")
        old_crcs = {i.filename: i.CRC for i in old.infolist() if i.filename not in replacements}
        new_crcs = {i.filename: i.CRC for i in new.infolist() if i.filename not in replacements and i.filename not in additions}
        if old_crcs != new_crcs or any(name not in new.namelist() for name in additions):
            sys.exit("archive contents differ beyond the rebrand")
    print(f"{args.destination}: {before['CFBundleIdentifier']} {before['CFBundleShortVersionString']} ({before['CFBundleVersion']}) "
          f"→ «{args.name}», {len(localized)} localized names checked, {len(replacements) - 1} changed, {len(additions)} icon files")


if __name__ == "__main__":
    main()
