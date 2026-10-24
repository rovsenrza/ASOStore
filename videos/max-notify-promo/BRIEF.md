---
workflow: general-video
flow: automation
storyboard: no
message: "MAX через Ru App Store теперь присылает уведомления и звонки — как оригинал"
destination: social-vertical (Telegram, Reels, Shorts, Stories)
aspect: "9:16"
language: ru
length: 15s
audio: music + sfx
---

## Intent
User (az): «realistik iPhone mockupunda 15 saniyəlik reklam video: MAX-dan bildiriş gəlməsi, zəng gəlməsi,
MAX app ikonunun üzərində rəqəmlə bildiriş sayı, iPhone-un hərəkətləri və bizim brendin reklamı.»

## Scenes
1. Lock screen — MAX notifications arrive one by one, phone buzzes.
2. Incoming MAX audio call on the lock screen — phone rings/vibrates.
3. Home screen — MAX icon badge counter climbs; camera pushes in.
4. Ru App Store brand end card — logo, CTA, ruappstore.com.

## Assets
- 3D iPhone: registry block vfx-iphone-device (models/iphone.glb), recolored to brand blue.
- MAX icon + catalog icons from Ru App Store catalog; logo assets/brand/logo.png.
- Fonts: Unbounded (display) + Onest (UI) — the site's own fonts.
- Palette: site tokens navy #06164d, blue #0b45e6, sky #239cf8, mist #e3ecff.

## Notes
- HeyGen signed out → music synthesized locally (scripts/make_audio.py), SFX synthesized + bundled library.
