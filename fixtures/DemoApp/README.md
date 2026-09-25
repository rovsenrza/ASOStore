# DemoApp

The authorized test IPA for the install pipeline (FULL_PLAN §18.4, IMPLEMENTATION_PLAN P5-OPS-01):
a one-screen SwiftUI app that shows «Установлено через Storefront» when it launches.

```sh
./scripts/export-demo-ipa.sh                     # → fixtures/DemoApp/build/DemoApp.ipa
DEMO_BUNDLE_ID=com.yourteam.demo DEMO_VERSION=1.0.1 DEMO_BUILD=2 ./scripts/export-demo-ipa.sh
```

The IPA is **unsigned** on purpose: the signing runner re-signs it for each device with the ad hoc
profile. It is built with `swiftc` for `arm64-apple-ios18.0`, so no Xcode project or Apple account is
needed to produce it. Upload it in Admin → Артефакты with source type «Собственная сборка»,
approve the team for its bundle ID (Команды Apple → Разрешения приложений), review and publish.

Set `DEMO_BUNDLE_ID` to the final prefix once P0-04 is decided; the default is a placeholder.
