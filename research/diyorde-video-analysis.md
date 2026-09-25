# DIYORDE video analysis — IMG_0377.MP4

Analyzed: 2026-09-23

## Video facts

- Duration: 342.4 seconds (5:42).
- Video: H.264, 1920×1322, 50 fps.
- Audio: AAC, 44.1 kHz, stereo.
- Audio is Russian and was transcribed locally with Whisper for timestamped review.

## Observed flow

1. The user starts on the iOS Home Screen and opens Safari.
2. `diyorde.com` presents Russian pricing cards: 1, 3, or 6 months. The narrator says the plans differ by duration, not feature access.
3. The user taps the device-profile installation flow. Safari shows the iOS system prompt to download/install a configuration profile.
4. The user opens Settings, sees “Profile Downloaded”, installs the profile, enters the device passcode, and confirms installation.
5. Safari returns to an activation form requesting name, email, and an activation code obtained from DIYORDE's Telegram bot.
6. After activation, Safari presents “Install DIYORDE” / “DDE Store”. iOS shows a system dialog: `diyorde.com` wants to install `DDE Store`.
7. DDE Store appears on the Home Screen.
8. On first launch, DDE Store tells the user to enable iOS Developer Mode. The narrator explicitly says this is required for apps installed this way. The user enables Developer Mode under Privacy & Security and reboots the device.
9. DDE Store opens with four top-level areas: `Главная`, `Игры`, `Приложения`, and `Менеджер`, plus search and account/notification controls.
10. The catalog contains native app icons and install buttons for apps such as MAX, VK, VK Video, T-Банк, Mail.ru, WhatsApp, WA Business, and games.
11. The Manager screen has two import paths: `Импортировать IPA с устройства` and `Импортировать IPA по ссылке`.
12. The app detail page for `Автотека` exposes version `2.5.3`, size `182.5 MB`, and Bundle ID `ru.abd.autoteka.application`; the bottom install queue shows `Готово к установке` and an `Установить` button.
13. The narrator says an app first enters a preparation state, then the user presses Install again and iOS starts the installation.

## Audio evidence (translated summary)

- 00:00–00:40: enable VPN if necessary, then open Safari.
- 00:50–01:40: choose a 1/3/6-month plan; previously installed apps keep working after expiry, but new installs and updates are blocked.
- 01:47–02:50: install a profile to register the device; iOS may require waiting up to one hour before the profile can be installed.
- 02:54–03:55: submit name, email, and activation code; successful activation unlocks DDE Store installation.
- 04:00–04:52: install DDE Store, enable Developer Mode, reboot, and launch the store.
- 04:59–05:42: use games/apps catalog or IPA Manager; import an IPA from the device/link, prepare it, and install it.

## Technical conclusion

The video proves the following architecture at the product level:

```text
Website/PWA
  → configuration profile / device identification
  → activation-code backend
  → install DDE Store native build
  → user enables Developer Mode
  → DDE Store catalog / IPA manager
  → prepare a selected IPA
  → iOS install confirmation
```

The Developer Mode requirement is important: this is not enough evidence for a classic App Store or Apple's official alternative marketplace. It is consistent with a developer-signed/device-registered build workflow. A classic Ad Hoc profile normally does not require the user to enable Developer Mode, so the video points more strongly to development-signed or otherwise developer-mode-gated installation than to a pure Ad Hoc-only flow.

The video does not reveal the IPA source, signing certificate, provisioning profile type, or whether the catalog apps are authorized by their copyright holders. The Manager's “import IPA from device/link” indicates that the platform can process user-supplied IPA inputs; it does not prove that the catalog binaries come from an official Apple repository.

## Implication for our product

To match the observed flow, our plan needs two distinct layers:

- A web activation/PWA layer for device profile, payment/code, and activation.
- A native DDE-like store app with `Главная`, `Игры`, `Приложения`, `Менеджер`, search, catalog install states, and user IPA import.

The native store cannot obtain arbitrary App Store binaries through an official public API. Catalog binaries must come from our own builds, an app developer's authorized package, an open-source build we are allowed to compile, or a user-supplied IPA that we are legally allowed to process.
