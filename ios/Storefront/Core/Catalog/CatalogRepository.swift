import Foundation

/// A value that may come from the offline cache.
nonisolated struct Fetched<Value: Sendable>: Sendable {
    let value: Value
    /// True when the network failed and the cached copy is shown instead.
    let isStale: Bool
}

/// Catalog reads for the native app (FULL_PLAN §11 "CatalogRepository").
nonisolated struct CatalogRepository: Sendable {
    let api: APIClient
    let cache: ResponseCache

    func feed() async throws -> Fetched<FeedDTO> {
        try await cached("feed") { try await api.get("/storefront/feed", as: FeedDTO.self).data }
    }

    func app(id: String) async throws -> Fetched<AppDetailDTO> {
        try await cached("app-\(id)") { try await api.get("/apps/\(id)", as: AppDetailDTO.self).data }
    }

    func search(_ query: String) async throws -> [AppSummaryDTO] {
        try await api.get("/apps", query: [URLQueryItem(name: "q", value: query), URLQueryItem(name: "per_page", value: "50")], as: [AppSummaryDTO].self).data
    }

    func apps(category: String? = nil) async throws -> Fetched<[AppSummaryDTO]> {
        var query = [URLQueryItem(name: "per_page", value: "50")]
        if let category {
            query.append(URLQueryItem(name: "category", value: category))
        }
        return try await cached("apps-\(category ?? "all")") {
            try await api.get("/apps", query: query, as: [AppSummaryDTO].self).data
        }
    }

    private func cached<Value: Codable & Sendable>(_ key: String, load: () async throws -> Value) async throws -> Fetched<Value> {
        do {
            let value = try await load()
            cache.store(value, key: key)
            return Fetched(value: value, isStale: false)
        } catch let error as APIError where error.code == .offline || error.code == .networkError {
            guard let cached = cache.load(Value.self, key: key) else { throw error }
            return Fetched(value: cached, isStale: true)
        }
    }
}
