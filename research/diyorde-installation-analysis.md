# DIYORDE installation mechanism analysis

Date checked: 2026-09-23

## Conclusion

DIYORDE's public evidence describes a web/PWA storefront backed by device-bound iOS app signing and Safari-initiated installation. It does not describe Apple's official Alternative Marketplace / MarketplaceKit flow. The evidence strongly matches Ad Hoc-style provisioning, although the exact certificate and provisioning profile inside a paid install cannot be cryptographically confirmed without purchasing and inspecting an installed package.

## Directly verified from DIYORDE

- The public site ships a Web App Manifest with `display: standalone`, so the storefront itself can run as a PWA: <https://diyorde.com/manifest.webmanifest>.
- The site's metadata explicitly advertises “iOS app signing,” “install IPA,” and “sideload iOS apps”: <https://diyorde.com/>.
- Setup installs a configuration profile that reports a device identifier; DIYORDE says the app build is tied to that identifier and compares it to developer test-device registration: <https://diyorde.com/faq>.
- After device registration, DIYORDE says an app build is prepared and an install link becomes available: <https://diyorde.com/faq>.
- Updates do not come through the App Store. Users revisit the install link and install the new build again: <https://diyorde.com/faq>.
- Installation must start from Safari, according to DIYORDE: <https://diyorde.com/faq>.
- Users may need to trust a new signing identity under Settings → General → VPN & Device Management: <https://diyorde.com/faq> and <https://diyorde.com/guides/app-is-untrusted>.
- DIYORDE sells “revoke protection” and tells users to reinstall when an activation stops working: <https://diyorde.com/terms> and <https://diyorde.com/faq>.
- DIYORDE's Terms call the product an activation/configuration service and state that users are responsible for having rights to installed application files: <https://diyorde.com/terms>.

## Interpretation

The combination of UDID/device-identifier collection, a build tied to a registered device, a signing identity, reinstall-based updates, and revocation recovery strongly indicates server-side IPA preparation/signing followed by over-the-air installation. These are characteristic of device-bound developer/Ad Hoc distribution rather than App Store distribution.

Apple confirms that registered devices are required for development or Ad Hoc provisioning and limits a developer membership to 100 devices per product family per membership year: <https://developer.apple.com/help/account/devices/devices-overview> and <https://developer.apple.com/help/account/devices/register-a-single-device>.

The likely sequence is:

1. A web configuration profile collects the device UDID.
2. The backend associates the device with an order and signing capacity.
3. A selected native IPA is prepared and signed for that device/signing identity.
4. Safari opens an install link that initiates iOS over-the-air installation.
5. Updates are new signed builds installed again from the same private link.
6. If the signing identity/profile is revoked or replaced, the service re-signs and asks the user to reinstall.

## Not publicly verifiable

- Whether a given build uses an Ad Hoc distribution profile, enterprise distribution, or another developer-signing arrangement. Device binding makes Ad Hoc the strongest fit, but a paid installed package would need inspection to prove it.
- The source of third-party IPA binaries and whether DIYORDE has redistribution authorization from each app owner.
- Whether DIYORDE uses one Apple developer team, multiple teams, an enterprise certificate, or an external signing provider.
- The authenticated install endpoint, OTA manifest, provisioning profile, entitlements, and signing certificate are not exposed before purchase.

## Important discrepancy

DIYORDE's Terms say it does not host or distribute application files, while its FAQ says it maintains a supported-app catalog and prepares builds for installation. Public pages do not explain how these statements are reconciled. No conclusion about licensing or legality can be made from the accessible evidence alone.
