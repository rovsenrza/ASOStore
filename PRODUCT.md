# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Static HTML, CSS and vanilla JavaScript. Next.js and other frontend frameworks are explicitly out of scope for the web frontend.

## Users

The primary user owns or manages an iPhone and wants to register that device, install the native Storefront, and understand whether each requested app is ready to install.

Operators and publishers are secondary audiences handled by separate admin and publisher surfaces; they are not the primary audience of this frontend.

## Product Purpose

The web frontend is the bootstrap and recovery layer for a native iOS storefront. It explains the service, starts device registration, reports eligibility and preparation status, hands off installation of the native Storefront, and remains available when the native app needs recovery.

Success means a first-time user can understand the product, register a device, follow the waiting state, and reach the native Storefront installation handoff without operator assistance.

## Positioning

The product turns a normally opaque device-registration and signing workflow into a visible, state-aware path from device setup to a verified native Storefront installation.

## Operating Context

- The initial flow starts in Safari on an iPhone.
- Device identity is collected through an iOS configuration-profile callback flow.
- Apple-side device eligibility may remain pending and must be presented honestly.
- The native Storefront is prepared, signed, validated, and delivered only after the device is eligible.
- The web frontend remains the recovery and support channel if the native Storefront cannot open.
- Payment and checkout will be added later and are not part of the current implementation priority.

## Capabilities and Constraints

- Public landing and product explanation
- Device registration onboarding
- Registration/preparation status presentation
- Native Storefront installation handoff
- Activation and recovery entry points
- Responsive behavior for phone and desktop
- Accessible loading, error, pending, ready, expired and unsupported states
- No framework or build step is required for the frontend
- No Apple platform bypass, silent installation, jailbreak flow, or fabricated availability claim
- Backend calls are represented by a replaceable API adapter and local demo state until the backend is implemented

## Brand Commitments

- The product name is intentionally undecided and must remain `[BRAND]` as a visible placeholder.
- All user-facing web content is Russian.
- DIYORDE is a functional reference for the bootstrap-to-native-store journey, not a visual identity to copy.
- The primary product application is a native iOS Storefront; the web frontend must not present itself as the final store.

## Evidence on Hand

- [APPSTORE.md](APPSTORE.md): platform architecture and compliance boundaries
- [PLAN.md](PLAN.md): backend and infrastructure implementation stages
- [APP_PLAN.md](APP_PLAN.md): native iOS Storefront and web bootstrap responsibilities
- DIYORDE public flow analysis from the project discussion
- No approved logo, product name, screenshots, testimonials, customer marks, usage metrics, or commercial claims exist yet; future work must not fabricate them.

## Product Principles

- Make system state visible instead of pretending every operation is instant.
- Keep device setup comprehensible to a non-technical iPhone user.
- Treat security, signing validation, and recovery as parts of the experience.
- Keep the web bootstrap useful even when the native Storefront is unavailable.
- Separate verified product facts from illustrative demo data.
