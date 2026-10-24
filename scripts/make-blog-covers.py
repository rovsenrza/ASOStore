"""Renders blog covers (1200x630): brand line, big Unbounded headline, subtitle, a badge, and a
phone with the article's app icon(s), from one JSON spec per article in scripts/blog-covers/.

  python3 scripts/make-blog-covers.py scripts/blog-covers <catalog.tsv> front/public/assets/blog [slug ...]

catalog.tsv has one published app per line: seo_slug, name, category, icon_path (icons are fetched
from the live site). Needs Google Chrome, cwebp and ImageMagick. Writes {slug}.webp and {slug}.jpg."""
import html, json, pathlib, subprocess, sys, tempfile, urllib.request

FRONT = pathlib.Path(__file__).resolve().parent.parent / "front"
HERE = pathlib.Path(tempfile.mkdtemp(prefix="blog-covers-"))
(HERE / "icons").mkdir()
FONTS = FRONT / "node_modules/@fontsource-variable"
LOGO = FRONT / "public/assets/brand/ru-app-store-logo-180.png"
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

covers_dir, catalog, out_dir = map(pathlib.Path, sys.argv[1:4])
only = set(sys.argv[4:])
icons = {}
for line in catalog.read_text().splitlines():
    cols = line.split("\t")
    if len(cols) >= 4 and cols[3] != "-":
        icons[cols[0]] = (cols[1], cols[3])


def icon_file(slug: str) -> pathlib.Path:
    name, path = icons[slug]
    target = HERE / "icons" / f"{slug}.webp"
    if not target.exists():
        urllib.request.urlretrieve(f"https://ruappstore.com/storage/{path}", target)
    return target


def page(spec: dict) -> str:
    apps = spec.get("apps", [])[:6]
    e = html.escape
    if len(apps) == 1:
        phone = f"""<img class="big" src="{icon_file(apps[0]).as_uri()}">
          <div class="cap">{e(spec.get('label') or icons[apps[0]][0])}</div>
          <div class="btn">Установить</div>"""
    elif apps:
        cells = "".join(f'<img src="{icon_file(a).as_uri()}">' for a in apps)
        cols = 2 if len(apps) in (2, 4, 6) else 3 if len(apps) == 3 else 2
        phone = f"""<div class="grid c{cols} n{len(apps)}">{cells}</div>
          <div class="cap">{e(spec.get('label', ''))}</div>"""
    else:
        phone = f"""<img class="big" src="{LOGO.as_uri()}"><div class="cap">{e(spec.get('label', ''))}</div>"""
    title = e(spec["title"].replace(" iPhone", "\u00a0iPhone").replace(" на ", " на\u00a0"))
    size = 64 if len(spec["title"]) <= 18 else 56 if len(spec["title"]) <= 26 else 48
    return f"""<!doctype html><html><head><meta charset="utf-8"><style>
@font-face {{ font-family: U; src: url("{(FONTS/'unbounded/files/unbounded-cyrillic-wght-normal.woff2').as_uri()}"); unicode-range: U+0400-04FF; font-weight: 200 900; }}
@font-face {{ font-family: U; src: url("{(FONTS/'unbounded/files/unbounded-latin-wght-normal.woff2').as_uri()}"); font-weight: 200 900; }}
@font-face {{ font-family: O; src: url("{(FONTS/'onest/files/onest-cyrillic-wght-normal.woff2').as_uri()}"); unicode-range: U+0400-04FF; font-weight: 100 900; }}
@font-face {{ font-family: O; src: url("{(FONTS/'onest/files/onest-latin-wght-normal.woff2').as_uri()}"); font-weight: 100 900; }}
* {{ margin: 0; box-sizing: border-box; }}
html, body {{ width: 1200px; height: 630px; overflow: hidden; }}
body {{ background: radial-gradient(120% 140% at 100% 0%, #2a8af0 0%, #1460ea 38%, #0b3fd6 70%, #0a36c4 100%); font-family: O; color: #fff; position: relative; }}
.brand {{ position: absolute; left: 72px; top: 64px; display: flex; align-items: center; gap: 14px; font-weight: 700; font-size: 22px; }}
.brand img {{ width: 44px; height: 44px; border-radius: 11px; }}
.brand span {{ opacity: .75; font-weight: 600; }}
.text {{ position: absolute; left: 72px; top: 0; bottom: 0; width: 640px; display: flex; flex-direction: column; justify-content: center; }}
h1 {{ font-family: U; font-weight: 800; font-size: {size}px; line-height: 1.12; letter-spacing: -.01em; }}
p {{ margin-top: 22px; font-size: 25px; line-height: 1.38; font-weight: 500; opacity: .9; max-width: 560px; }}
.badge {{ position: absolute; left: 72px; bottom: 64px; background: #fff; color: #0b3fd6; font-weight: 700; font-size: 20px; padding: 12px 22px; border-radius: 999px; }}
.phone {{ position: absolute; right: 66px; top: 46px; width: 298px; height: 538px; border-radius: 52px; background: #0e1f55; padding: 14px; box-shadow: 0 30px 80px rgba(0,10,60,.35); }}
.screen {{ width: 100%; height: 100%; border-radius: 40px; background: linear-gradient(180deg, #f3f6fd, #dde6fa); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; }}
.big {{ width: 150px; height: 150px; border-radius: 34px; box-shadow: 0 14px 30px rgba(10,30,90,.22); }}
.grid {{ display: flex; flex-wrap: wrap; justify-content: center; gap: 22px; }}
.grid.c2 {{ width: 206px; }}
.grid.c3 {{ width: 238px; gap: 14px; }}
.grid img {{ width: 92px; height: 92px; border-radius: 22px; box-shadow: 0 10px 22px rgba(10,30,90,.18); }}
.grid.c3 img {{ width: 70px; height: 70px; border-radius: 17px; }}
.grid.n2 img {{ width: 104px; height: 104px; border-radius: 24px; }}
.grid.n2 {{ width: 240px; }}
.cap {{ color: #0e1a3a; font-weight: 700; font-size: 22px; text-align: center; padding: 0 16px; line-height: 1.25; }}
.btn {{ background: #0b4be0; color: #fff; font-weight: 700; font-size: 20px; padding: 13px 34px; border-radius: 999px; }}
</style></head><body>
<div class="brand"><img src="{LOGO.as_uri()}">Ru App Store <span>· Блог</span></div>
<div class="text"><h1>{title}</h1><p>{e(spec.get('subtitle', ''))}</p></div>
<div class="badge">{e(spec.get('badge', ''))}</div>
<div class="phone"><div class="screen">{phone}</div></div>
</body></html>"""


out_dir.mkdir(parents=True, exist_ok=True)
for spec_path in sorted(covers_dir.glob("*.json")):
    slug = spec_path.stem
    if only and slug not in only:
        continue
    spec = json.loads(spec_path.read_text())
    missing = [a for a in spec.get("apps", []) if a not in icons]
    if missing:
        print(f"{slug}: unknown apps {missing}, dropped")
        spec["apps"] = [a for a in spec["apps"] if a in icons]
    doc = HERE / "html" / f"{slug}.html"
    doc.parent.mkdir(exist_ok=True)
    doc.write_text(page(spec))
    png = HERE / "png" / f"{slug}.png"
    png.parent.mkdir(exist_ok=True)
    subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
                    "--allow-file-access-from-files", "--virtual-time-budget=3000",
                    f"--screenshot={png}", "--window-size=1200,630", doc.as_uri()],
                   check=True, capture_output=True)
    subprocess.run(["cwebp", "-quiet", "-q", "86", str(png), "-o", str(out_dir / f"{slug}.webp")], check=True)
    subprocess.run(["magick", str(png), "-strip", "-quality", "86", str(out_dir / f"{slug}.jpg")], check=True)
    # ImageMagick and cwebp can leave 0600 files; rsync -a carries that to the docroot and nginx returns 403.
    for ext in ("webp", "jpg"):
        (out_dir / f"{slug}.{ext}").chmod(0o644)
    print(f"{slug}: ok")
