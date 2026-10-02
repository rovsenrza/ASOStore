# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Static HTML, CSS and vanilla JavaScript. Next.js and other frontend frameworks are explicitly out of scope for the web frontend.

## Users

The primary user owns or manages an iPhone and wants to register that device, install the native Ru App Store app, and understand whether each requested app is ready to install.

Operators and publishers are secondary audiences handled by separate admin and publisher surfaces; they are not the primary audience of this frontend.

## Product Purpose

The web frontend is the bootstrap and recovery layer for the native Ru App Store app. It explains the service, starts device registration, reports eligibility and preparation status, hands off installation of Ru App Store, and remains available when the native app needs recovery.

Success means a first-time user can understand the product, register a device, follow the waiting state, and reach the Ru App Store installation handoff without operator assistance.

## Positioning

The product turns a normally opaque device-registration and signing workflow into a visible, state-aware path from device setup to a verified Ru App Store installation.

## Operating Context

- The initial flow starts in Safari on an iPhone.
- Device identity is collected through an iOS configuration-profile callback flow.
- Apple-side device eligibility may remain pending and must be presented honestly.
- Ru App Store is prepared, signed, validated, and delivered only after the device is eligible.
- The web frontend remains the recovery and support channel if Ru App Store cannot open.
- Purchase happens in the Telegram store bot (@RuAppStor_bot). The website's purchase page (`/buy.html`) and the packages block show the bot's current prices (`GET /api/v1/store/offer`) and hand off with a deep link (`t.me/<bot>?start=buy_<plan>`) that opens the order for the chosen term. The bot issues the activation code; the website redeems it during device registration.

## Capabilities and Constraints

- Public landing and product explanation
- Device registration onboarding
- Registration/preparation status presentation
- Ru App Store installation handoff
- Activation and recovery entry points
- Responsive behavior for phone and desktop
- Accessible loading, error, pending, ready, expired and unsupported states
- The web frontend uses vanilla HTML, CSS and JavaScript with a Vite production build. No frontend framework is used.
- No Apple platform bypass, silent installation, jailbreak flow, or fabricated availability claim
- The website uses the live Laravel API through the shared API client. Mock responses are available only in local development.

## Brand Commitments

- The product name is **Ru App Store** (decided 2026-09-26). Logo sources are in `branding/`.
- All user-facing web content is Russian.
- DIYORDE is a functional reference for the bootstrap-to-native-store journey, not a visual identity to copy.
- The primary product application is native Ru App Store for iOS; the web frontend must not present itself as the final store.

## Evidence on Hand

- [APPSTORE.md](APPSTORE.md): platform architecture and compliance boundaries
- [PLAN.md](PLAN.md): backend and infrastructure implementation stages
- [APP_PLAN.md](APP_PLAN.md): native iOS Storefront and web bootstrap responsibilities
- DIYORDE public flow analysis from the project discussion
- The Ru App Store name and logo are approved for this project. No approved testimonials, customer marks, usage metrics, or commercial claims exist yet; future work must not fabricate them.

## Product Principles

- Make system state visible instead of pretending every operation is instant.
- Keep device setup comprehensible to a non-technical iPhone user.
- Treat security, signing validation, and recovery as parts of the experience.
- Keep the web bootstrap useful even when the native Ru App Store app is unavailable.
- Separate verified product facts from illustrative demo data.

## Website Offer and Copy

- Primary message: familiar apps removed from the Russian App Store can be discovered in Ru App Store and prepared for an eligible iPhone.
- Primary action: choose a term and buy it in the Telegram bot, then register the device in Safari. The website must keep preparation and Apple waiting states visible.
- Term options displayed on the site: 1 month for 590 ₽, 6 months for 1,770 ₽, and 12 months for 2,360 ₽ by default; admins change prices in the bot and the site follows. The 6 month option is the initial selection. All options list the same access features.
- Catalog proof uses actual app names and icon images from the catalog. The total count is fetched from the API; no number is rendered when the request fails.
- The website itself takes no payment. Which payment methods exist is decided by the bot's configuration (no online provider is connected yet), so site copy must not promise instant online payment or an instant code: the code arrives "after the payment is confirmed". The referral share is shown only from the live offer, never as a hard-coded number.
- The Telegram section (bot, news channel, support chat) appears on the homepage, the purchase page and the support page; its links come from the bot's configuration.
- No testimonials, install guarantees, instant Apple registration promises, or fabricated rankings are approved.
