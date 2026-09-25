# iOS Storefront

SwiftUI app, iOS 18+, Swift 6 with main-actor default isolation. Open `Storefront.xcodeproj`.

## Configurations

| Configuration | xcconfig | API | Data |
|---|---|---|---|
| Debug | `Config/Local.xcconfig` | `http://127.0.0.1:8000/api/v1` | Mock fixtures by default; launch argument `-apiMode live` uses the local backend |
| Staging | `Config/Staging.xcconfig` | Placeholder until the domain is chosen | Live only |
| Release | `Config/Production.xcconfig` | Placeholder until the domain is chosen | Live only |

The bundle ID is a placeholder in `Config/Shared.xcconfig`. It must be fixed in Phase 0 before any Apple registration.

## Structure

- `Storefront/Core/Networking` — `APIClient` (actor, envelope decoding, `X-Request-Id`), transports. `MockTransport` serves `docs/api/examples` (linked into the project, not copied) and exists in Debug builds only.
- `Storefront/Core/Models` — `InstallState` drives every CTA; `API/` holds wire types for `docs/api/openapi.yaml`.
- `Storefront/Core/State/LoadState.swift` + `Components/StateContainerView.swift` — the screen states from FULL_PLAN §11.
- `Storefront/Features` — Today, Browse, Search, Library, Account, AppDetail. Screens still use `Core/Mock/MockCatalog` until Phase 4 connects them to the API.
- `StorefrontTests` — Swift Testing: fixture decoding, API client, install state, mock routing.

Debug launch arguments: `-demoTab today|apps|search|library|account`, `-apiMode mock|live`.
