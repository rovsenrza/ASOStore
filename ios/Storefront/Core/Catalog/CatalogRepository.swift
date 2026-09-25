import Foundation

/// A value that may come from the offline cache.
nonisolated struct Fetched<Value: Sendable>: Sendable {
    let value: Value
    /// True when the network failed and the cached copy is shown instead.
    let isStale: Bool
}

/// Which part of the catalog a tab shows.
nonisolated enum CatalogKind: String, Sendable {
    case all, games, apps

    var queryValue: String? {
        self == .all ? nil : rawValue
    }
}

nonisolated enum CatalogSort: String, Sendable {
    case featured, updated, new
}

/// Catalog reads for the native app (FULL_PLAN §11 "CatalogRepository").
nonisolated struct CatalogRepository: Sendable {
    let api: APIClient
    let cache: ResponseCache

    /// Curated sections for one tab: everything (Home), or only games or apps.
    func feed(_ kind: CatalogKind = .all) async throws -> Fetched<FeedDTO> {
        let query = kind.queryValue.map { [URLQueryItem(name: "kind", value: $0)] } ?? []
        return try await cached("feed-\(kind.rawValue)") { try await api.get("/storefront/feed", query: query, as: FeedDTO.self).data }
    }

    /// One sorted page of apps plus the total count (Search: «Обновлено» / «Новое»).
    func page(sort: CatalogSort, kind: CatalogKind = .all, perPage: Int = 50) async throws -> (apps: [AppSummaryDTO], total: Int) {
        var query = [URLQueryItem(name: "sort", value: sort.rawValue), URLQueryItem(name: "per_page", value: String(perPage))]
        if let kind = kind.queryValue {
            query.append(URLQueryItem(name: "kind", value: kind))
        }
        let response = try await api.get("/apps", query: query, as: [AppSummaryDTO].self)
        return (response.data, response.meta.pagination?.total ?? response.data.count)
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
