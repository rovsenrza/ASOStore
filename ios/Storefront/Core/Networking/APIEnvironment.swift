import Foundation

/// Where API requests go. Values come from Config/*.xcconfig through Info.plist.
nonisolated struct APIEnvironment: Equatable, Sendable {
    enum Mode: String, Sendable {
        /// Real backend at `baseURL`.
        case live
        /// Bundled docs/api/examples fixtures (Debug builds only).
        case mock
    }

    let baseURL: URL
    let mode: Mode

    /// The web portal on the same origin (IMPLEMENTATION_PLAN D1): registration,
    /// password reset and recovery happen there.
    var portalURL: URL {
        get throws {
            guard var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false) else {
                throw URLError(.badURL)
            }
            components.path = "/"
            guard let url = components.url else { throw URLError(.badURL) }
            return url
        }
    }

    /// Used when Info.plist has no usable StorefrontAPIBaseURL (a local backend in development).
    static let fallbackBaseURL = "http://127.0.0.1:8000/api/v1"

    static func baseURL(from raw: String?) throws -> URL {
        guard let raw, let url = URL(string: raw), url.scheme != nil else { throw URLError(.badURL) }
        return url
    }

    /// «Купить доступ» on the portal: tariffs, purchase and renewal (front/src/buy.html;
    /// pricing.html redirects there).
    var renewURL: URL? {
        (try? portalURL)?.appending(path: "buy.html")
    }

    static func current(
        bundle: Bundle = .main,
        arguments: [String] = ProcessInfo.processInfo.arguments
    ) -> APIEnvironment {
        let rawURL = bundle.object(forInfoDictionaryKey: "StorefrontAPIBaseURL") as? String
        let baseURL = (try? baseURL(from: rawURL))
            ?? (try? baseURL(from: fallbackBaseURL))
            ?? URL(fileURLWithPath: "/")

        #if DEBUG
        var mode = (bundle.object(forInfoDictionaryKey: "StorefrontAPIMode") as? String).flatMap(Mode.init(rawValue:)) ?? .live
        if let index = arguments.firstIndex(of: "-apiMode"),
           arguments.indices.contains(index + 1),
           let override = Mode(rawValue: arguments[index + 1]) {
            mode = override
        }
        #else
        let mode = Mode.live
        #endif

        return APIEnvironment(baseURL: baseURL, mode: mode)
    }
}
