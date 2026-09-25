import Foundation

/// Links the app understands (FULL_PLAN §11 "DeepLinkRouter").
nonisolated enum DeepLink: Equatable, Sendable {
    /// storefront://claim?code=… — one-time sign-in handed over by the portal.
    case claim(code: String)
    /// storefront://app/<public id>
    case app(id: String)

    init?(url: URL) {
        guard url.scheme?.lowercased() == "storefront" else { return nil }
        let components = URLComponents(url: url, resolvingAgainstBaseURL: false)

        switch url.host()?.lowercased() {
        case "claim":
            guard let code = components?.queryItems?.first(where: { $0.name == "code" })?.value, !code.isEmpty else { return nil }
            self = .claim(code: code)
        case "app":
            guard let id = url.pathComponents.dropFirst().first, !id.isEmpty else { return nil }
            self = .app(id: id)
        default:
            return nil
        }
    }
}
