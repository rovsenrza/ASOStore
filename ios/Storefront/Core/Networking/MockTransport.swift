#if DEBUG
import Foundation

/// Serves the shared docs/api/examples fixtures bundled into Debug builds, so
/// UI work and UI tests run without a backend. Never compiled into Release.
///
/// Launch argument `-mockScenario offline|empty|error` forces the screen
/// states UI tests check (FULL_PLAN §11 "UI states").
nonisolated struct MockTransport: HTTPTransport {
    enum Scenario: String, Sendable {
        case normal, offline, empty, unauthorized, expired, error

        static func fromLaunchArguments(_ arguments: [String] = ProcessInfo.processInfo.arguments) -> Scenario {
            guard let index = arguments.firstIndex(of: "-mockScenario"), arguments.indices.contains(index + 1) else { return .normal }
            return Scenario(rawValue: arguments[index + 1]) ?? .normal
        }
    }

    /// Fixture for a request, matched on the path's trailing components.
    static func fixture(method: String?, path: String) -> String? {
        let parts = path.split(separator: "/").map(String.init)
        let tail = (parts.dropLast(2).last, parts.dropLast().last, parts.last)

        if method == "POST" {
            switch tail {
            case (_, "auth", "tokens"), (_, "auth", "refresh"): return "auth-tokens"
            case (_, "auth", "logout"): return "empty"
            case (_, "activation", "redeem"): return "activation-redeem"
            case ("storefront", "claims", "redeem"): return "storefront-claim-redeem"
            case ("apps", _, "prepare"): return "installation-preparing"
            case ("installations", _, "authorize"): return "install-link"
            default: return nil
            }
        }
        guard method == "GET" else { return nil }

        switch tail {
        case (_, _, "health"): return "health"
        case (_, _, "library"): return "library"
        case (_, "installations", _): return "installation-ready"
        case (_, "auth", "me"): return "auth-me"
        case (_, "devices", "me"): return "devices-me"
        case (_, "storefront", "feed"): return "storefront-feed"
        case (_, "storefront", "status"): return "storefront-status-signed-out"
        case (_, _, "apps"): return "apps-list"
        case ("apps", _, "versions"): return "app-versions"
        case (_, "apps", _): return "app-detail"
        default: return nil
        }
    }

    let bundle: Bundle
    let latency: Duration
    let scenario: Scenario

    init(bundle: Bundle = .main, latency: Duration = .milliseconds(300), scenario: Scenario = .fromLaunchArguments()) {
        self.bundle = bundle
        self.latency = latency
        self.scenario = scenario
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        try await Task.sleep(for: latency)

        switch scenario {
        case .offline:
            throw URLError(.notConnectedToInternet)
        case .unauthorized:
            return respond(request, status: 401, body: errorBody(code: "UNAUTHENTICATED", message: "Требуется вход."))
        case .expired:
            return respond(request, status: 401, body: errorBody(code: "SESSION_EXPIRED", message: "Сеанс истёк."))
        case .error:
            return respond(request, status: 500, body: errorBody(code: "INTERNAL", message: "Внутренняя ошибка."))
        case .empty, .normal:
            break
        }

        let route = Self.fixture(method: request.httpMethod, path: request.url?.path() ?? "")
        if scenario == .empty, route == "storefront-feed" {
            return respond(request, status: 200, body: Data(#"{"data":{"sections":[]},"meta":{"request_id":"mock"},"error":null}"#.utf8))
        }
        if scenario == .empty, route == "apps-list" {
            return respond(request, status: 200, body: Data(#"{"data":[],"meta":{"request_id":"mock","pagination":{"page":1,"per_page":50,"total":0,"last_page":1}},"error":null}"#.utf8))
        }

        var fixture = route ?? "error-not-found"
        // The Games and Apps tabs ask for one kind of feed.
        if route == "storefront-feed",
           let kind = request.url.flatMap({ URLComponents(url: $0, resolvingAgainstBaseURL: false) })?.queryItems?.first(where: { $0.name == "kind" })?.value,
           ["games", "apps"].contains(kind) {
            fixture = "storefront-feed-\(kind)"
        }
        var body: Data
        if fixture == "empty" {
            body = Data(#"{"data":null,"meta":{"request_id":"mock"},"error":null}"#.utf8)
        } else if let url = bundle.url(forResource: fixture, withExtension: "json") {
            body = try Data(contentsOf: url)
        } else {
            throw URLError(.fileDoesNotExist)
        }

        if fixture == "apps-list", let components = request.url.flatMap({ URLComponents(url: $0, resolvingAgainstBaseURL: false) }) {
            body = Self.filterApps(body, query: components.queryItems ?? [])
        }

        return respond(request, status: route == nil ? 404 : 200, body: body)
    }

    private func errorBody(code: String, message: String) -> Data {
        let body: [String: Any] = [
            "data": NSNull(),
            "meta": ["request_id": "mock-error"],
            "error": ["code": code, "message": message, "details": [:]],
        ]
        return (try? JSONSerialization.data(withJSONObject: body)) ?? Data()
    }

    /// Applies ?q=, ?category= and ?kind= to the apps-list fixture, like the API does.
    static func filterApps(_ body: Data, query: [URLQueryItem]) -> Data {
        let q = query.first { $0.name == "q" }?.value?.lowercased() ?? ""
        let category = query.first { $0.name == "category" }?.value
        let kind = query.first { $0.name == "kind" }?.value?.uppercased()
        guard (!q.isEmpty || category != nil || kind != nil),
              var envelope = try? JSONSerialization.jsonObject(with: body) as? [String: Any],
              let apps = envelope["data"] as? [[String: Any]] else { return body }

        let filtered = apps.filter { app in
            let categoryInfo = app["category"] as? [String: Any]
            let publisher = (app["publisher"] as? [String: Any])?["name"] as? String ?? ""
            let haystack = [app["name"] as? String, app["subtitle"] as? String, publisher, categoryInfo?["title"] as? String]
                .compactMap { $0?.lowercased() }
            let matchesQuery = q.isEmpty || haystack.contains { $0.contains(q) }
            let matchesCategory = category == nil || categoryInfo?["slug"] as? String == category
            let matchesKind = kind == nil || categoryInfo?["kind"] as? String == kind
            return matchesQuery && matchesCategory && matchesKind
        }
        envelope["data"] = filtered
        if var meta = envelope["meta"] as? [String: Any], var pagination = meta["pagination"] as? [String: Any] {
            pagination["total"] = filtered.count
            meta["pagination"] = pagination
            envelope["meta"] = meta
        }
        return (try? JSONSerialization.data(withJSONObject: envelope)) ?? body
    }

    private func respond(_ request: URLRequest, status: Int, body: Data) -> (Data, HTTPURLResponse) {
        let response = HTTPURLResponse(
            url: request.url!,
            statusCode: status,
            httpVersion: "HTTP/1.1",
            headerFields: [
                "Content-Type": "application/json",
                "X-Request-Id": request.value(forHTTPHeaderField: "X-Request-Id") ?? "mock",
            ]
        )!
        return (body, response)
    }
}
#endif
