import Foundation
import Testing
@testable import Storefront

@Suite("Mock transport filters")
struct MockTransportFilterTests {
    @Test func filtersAppsByQueryAndCategory() throws {
        let body = try Fixtures.data("apps-list")
        let byQuery = MockTransport.filterApps(body, query: [URLQueryItem(name: "q", value: "ПОЧТ")])
        let byCategory = MockTransport.filterApps(body, query: [URLQueryItem(name: "category", value: "weather")])

        #expect(names(byQuery) == ["Orbit Mail"])
        #expect(names(byCategory) == ["Pixel Weather"])
    }

    private func names(_ data: Data) -> [String] {
        let envelope = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        return (envelope?["data"] as? [[String: Any]])?.compactMap { $0["name"] as? String } ?? []
    }
}

@Suite("Mock catalog")
struct MockCatalogTests {
    @Test func libraryListsFollowInstallState() {
        #expect(MockCatalog.updates.allSatisfy { $0.installState == .updateAvailable })
        #expect(MockCatalog.delivered.allSatisfy { $0.installState == .delivered })
        #expect(!MockCatalog.updates.isEmpty)
    }
}
