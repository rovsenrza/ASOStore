import Foundation
import Testing
@testable import Storefront

@Suite("Catalog repository")
struct CatalogRepositoryTests {
    private func temporaryCache() -> ResponseCache {
        ResponseCache(directory: FileManager.default.temporaryDirectory.appending(path: "catalog-tests-\(UUID().uuidString)"))
    }

    @Test func servesTheCachedFeedWhenOffline() async throws {
        let cache = temporaryCache()
        let online = CatalogRepository(api: .stubbed(StubTransport(body: try Fixtures.data("storefront-feed"))), cache: cache)
        let fresh = try await online.feed()
        #expect(!fresh.isStale)

        let offline = CatalogRepository(api: .stubbed(StubTransport(error: URLError(.notConnectedToInternet))), cache: cache)
        let cached = try await offline.feed()

        #expect(cached.isStale)
        #expect(cached.value.sections.map(\.id) == fresh.value.sections.map(\.id))
    }

    @Test func reportsOfflineWithoutACache() async throws {
        let repository = CatalogRepository(api: .stubbed(StubTransport(error: URLError(.notConnectedToInternet))), cache: temporaryCache())

        let error = await #expect(throws: APIError.self) { try await repository.feed() }
        #expect(error?.code == .offline)
    }

    @Test func doesNotHideServerErrorsBehindTheCache() async throws {
        let cache = temporaryCache()
        _ = try await CatalogRepository(api: .stubbed(StubTransport(body: try Fixtures.data("storefront-feed"))), cache: cache).feed()

        let failing = CatalogRepository(api: .stubbed(StubTransport(status: 500, body: Envelopes.error("INTERNAL"))), cache: cache)
        let error = await #expect(throws: APIError.self) { try await failing.feed() }
        #expect(error?.code == .internal)
    }

    @Test func mapsApiAppsToTheViewModel() throws {
        let detail = try JSONDecoder.api.decode(APIEnvelope<AppDetailDTO>.self, from: Fixtures.data("app-detail")).data
        let app = StoreApp(detail)

        #expect(app.name == "Focus Notes")
        #expect(app.developer == "North Studio")
        #expect(app.systemImage == "checkmark.circle.fill")
        #expect(app.installState == .get)
        #expect(app.size != "—")
    }
}

extension JSONDecoder {
    static var api: JSONDecoder {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        decoder.dateDecodingStrategy = .iso8601
        return decoder
    }
}
