import Foundation
import Testing
@testable import Storefront

/// `GET /apps/{id}` gained optional fields (downloads, share_url, related, more_from_publisher,
/// versions). Older servers and responses cached before the update lack them, and the page
/// must open either way.
@Suite("App page data")
struct AppDetailMappingTests {
    private static let newKeys = ["downloads", "share_url", "related", "more_from_publisher", "versions"]

    /// The app-detail fixture's `data`, edited as a JSON object and decoded like an API response.
    private func detail(_ edit: (inout [String: Any]) -> Void = { _ in }) throws -> AppDetailDTO {
        var envelope = try #require(JSONSerialization.jsonObject(with: Fixtures.data("app-detail")) as? [String: Any])
        var data = try #require(envelope["data"] as? [String: Any])
        edit(&data)
        envelope["data"] = data
        return try JSONDecoder.api.decode(APIEnvelope<AppDetailDTO>.self, from: JSONSerialization.data(withJSONObject: envelope)).data
    }

    // MARK: Decoding

    @Test func decodesTheNewFields() throws {
        let detail = try detail()

        #expect(detail.downloads == 1234)
        #expect(detail.shareUrl == "https://ruappstore.com/app/focus-notes")
        #expect(detail.related?.map(\.slug) == ["orbit-mail", "paper-scan", "lingua", "tempo", "habit-garden"])
        #expect(detail.moreFromPublisher?.map(\.slug) == ["focus-tasks", "focus-timer"])
        #expect(detail.versions?.map(\.version) == ["3.4.0", "3.3.0", "3.2.1"])
        #expect(detail.versions?.first?.releasedAt != nil)
        #expect(detail.versions?.last?.releaseNotes == nil)
    }

    @Test func decodesAResponseWithoutTheNewFields() throws {
        let detail = try detail { data in
            for key in Self.newKeys { data[key] = nil }
        }

        #expect(detail.slug == "focus-notes")
        #expect(detail.downloads == nil)
        #expect(detail.shareUrl == nil)
        #expect(detail.related == nil)
        #expect(detail.moreFromPublisher == nil)
        #expect(detail.versions == nil)

        let app = StoreApp(detail)
        #expect(app.name == "Focus Notes")
        #expect(app.downloads == nil)
        #expect(app.shareURL == nil)
        #expect(app.related.isEmpty)
        #expect(app.moreFromPublisher.isEmpty)
        #expect(app.versions.isEmpty)
        #expect(app.earlierVersions.isEmpty)
        // `latest_version.released_at` was always there.
        #expect(app.updatedAt != nil)
    }

    @Test func decodesNullsForTheNewFields() throws {
        let detail = try detail { data in
            for key in Self.newKeys { data[key] = NSNull() }
        }

        #expect(detail.downloads == nil)
        #expect(detail.related == nil)
        #expect(detail.versions == nil)
    }

    @Test func malformedNewFieldsDoNotFailThePage() throws {
        let detail = try detail { data in
            data["downloads"] = "many"
            data["share_url"] = 42
            data["more_from_publisher"] = ["not": "a list"]
            // One good entry between two broken ones.
            var related = data["related"] as? [Any] ?? []
            related = [["id": "broken"], related[0], "nonsense"]
            data["related"] = related
            data["versions"] = [["version": "1.0"]]
        }

        #expect(detail.name == "Focus Notes")
        #expect(detail.downloads == nil)
        #expect(detail.shareUrl == nil)
        #expect(detail.moreFromPublisher == nil)
        #expect(detail.related?.map(\.slug) == ["orbit-mail"])
        #expect(detail.versions == [])
    }

    @Test func requiredFieldsAreStillRequired() throws {
        #expect(throws: DecodingError.self) {
            _ = try detail { $0["name"] = nil }
        }
    }

    @Test func survivesTheOfflineCacheRoundTrip() throws {
        let cache = ResponseCache(directory: FileManager.default.temporaryDirectory.appending(path: "detail-tests-\(UUID().uuidString)"))
        let full = try detail()
        cache.store(full, key: "app-full")
        #expect(cache.load(AppDetailDTO.self, key: "app-full") == full)

        let old = try detail { data in
            for key in Self.newKeys { data[key] = nil }
        }
        cache.store(old, key: "app-old")
        #expect(cache.load(AppDetailDTO.self, key: "app-old") == old)
    }

    // MARK: Mapping

    @Test func mapsTheNewFields() throws {
        let app = StoreApp(try detail())

        #expect(app.downloads == 1234)
        #expect(app.shareURL?.absoluteString == "https://ruappstore.com/app/focus-notes")
        #expect(app.buildNumber == "340")
        #expect(app.updatedAt != nil)
        #expect(app.related.map(\.name) == ["Orbit Mail", "Paper Scan", "Lingua", "Tempo", "Habit Garden"])
        #expect(app.related.first?.installState == .failed(reason: .incompatibleDevice))
        #expect(app.moreFromPublisher.map(\.name) == ["Focus Tasks", "Focus Timer"])
        #expect(app.versions.map(\.version) == ["3.4.0", "3.3.0", "3.2.1"])
        // The latest version is already under «Что нового».
        #expect(app.earlierVersions.map(\.version) == ["3.3.0", "3.2.1"])
        #expect(app.earlierVersions.last?.notes == "")
    }

    @Test func zeroDownloadsAreNotShown() throws {
        let app = StoreApp(try detail { $0["downloads"] = 0 })

        #expect(app.downloads == nil)
        #expect(!AppFact.facts(for: app).contains { $0.kind == .downloads })
        #expect(!AppInfoRow.rows(for: app).contains { $0.title == "Установки" })
    }

    @Test func shelvesNeverRepeatAnAppOrShowThePageItself() throws {
        let app = StoreApp(try detail { data in
            let related = data["related"] as? [[String: Any]] ?? []
            var own = related[0]
            own["id"] = data["id"]
            data["related"] = [own, related[0], related[0], related[1]]
            // Already in «Похожие приложения»: not again under «Ещё от издателя».
            data["more_from_publisher"] = [related[1]] + (data["more_from_publisher"] as? [[String: Any]] ?? [])
        })

        #expect(app.related.map(\.name) == ["Orbit Mail", "Paper Scan"])
        #expect(app.moreFromPublisher.map(\.name) == ["Focus Tasks", "Focus Timer"])
    }

    @Test func noPublisherShelfForAnUnknownDeveloper() throws {
        let app = StoreApp(try detail { data in
            var publisher = data["publisher"] as? [String: Any] ?? [:]
            publisher["name"] = "Разработчик не указан"
            data["publisher"] = publisher
        })

        #expect(!app.hasKnownDeveloper)
        #expect(app.moreFromPublisher.isEmpty)
        #expect(!app.related.isEmpty)
    }

    @Test func notesFallBackToTheVersionList() throws {
        let app = StoreApp(try detail { $0["release_notes"] = "  " })
        #expect(app.whatsNew == "Новый режим дня, быстрые виджеты и более удобная работа с тегами.")

        let none = StoreApp(try detail { data in
            data["release_notes"] = NSNull()
            data["versions"] = NSNull()
        })
        #expect(none.whatsNew.isEmpty)
    }

    @Test func aPageOpenedFromALinkIsAPlaceholder() {
        #expect(StoreApp.loading(id: "x").isPlaceholder)
        #expect(!StoreApp.loading(id: "x").hasKnownDeveloper)
        #expect(AppFact.facts(for: .loading(id: "x")).map(\.kind) == [.age])
    }

    @Test(arguments: [
        ("https://ruappstore.com/app/vk", false, "https://ruappstore.com/app/vk"),
        ("  http://example.com/help ", false, "http://example.com/help"),
        ("example.com/privacy", false, "https://example.com/privacy"),
        ("mailto:help@example.com", true, "mailto:help@example.com"),
        ("help@example.com", true, "mailto:help@example.com"),
    ])
    func linksThePageCanOpen(text: String, allowsMail: Bool, expected: String) {
        #expect(StoreApp.link(text, allowsMail: allowsMail)?.absoluteString == expected)
    }

    @Test(arguments: ["", "   ", "нет сайта", "https://", "javascript:alert(1)", "tel:+79990000000", "help@example.com", "mailto:help@example.com", "localhost"])
    func linksThePageWillNotOpen(text: String) {
        #expect(StoreApp.link(text) == nil)
    }

    @Test func missingLinkIsNil() {
        #expect(StoreApp.link(nil) == nil)
    }

    // MARK: Facts and information

    @Test func factsInOrder() throws {
        let app = StoreApp(try detail())
        let now = try #require(app.updatedAt).addingTimeInterval(15 * 86_400)
        let facts = AppFact.facts(for: app, now: now)

        #expect(facts.map(\.kind) == [.downloads, .age, .category, .size, .version, .compatibility, .updated])

        let downloads = try #require(facts.first)
        #expect(downloads.value == "1,2 тыс.")
        #expect(downloads.caption == "установок")
        #expect(downloads.spoken.hasSuffix("установки"))

        let category = facts[2]
        #expect(category.symbol == "checkmark.circle.fill")
        #expect(category.caption == "Продуктивность")

        #expect(facts[4].value == "3.4.0")
        #expect(facts[5].value == "iOS 17+")
        #expect(facts[5].spoken == "iOS 17 и новее")
        #expect(facts[6].spoken == "2 недели назад")
    }

    @Test func factsLeaveOutWhatIsUnknown() throws {
        let app = StoreApp(try detail { data in
            data["downloads"] = nil
            data["latest_version"] = NSNull()
            data["versions"] = nil
        })

        #expect(AppFact.facts(for: app).map(\.kind) == [.age, .category])
    }

    @Test func informationRows() throws {
        let app = StoreApp(try detail { data in
            data["support_url"] = "help@northstudio.example"
            data["privacy_url"] = "https://northstudio.example/privacy"
            var publisher = data["publisher"] as? [String: Any] ?? [:]
            publisher["website"] = "northstudio.example"
            data["publisher"] = publisher
        })
        let rows = AppInfoRow.rows(for: app)

        #expect(rows.map(\.title) == [
            "Продавец", "Категория", "Размер", "Совместимость", "Возраст", "Обновлено", "Установки", "Версия",
            "Поддержка", "Конфиденциальность", "Сайт разработчика",
        ])
        #expect(rows.first { $0.title == "Совместимость" }?.value == "iOS 17 и новее")
        #expect(rows.first { $0.title == "Версия" }?.value == "3.4.0 (340)")
        #expect(rows.first { $0.title == "Поддержка" }?.url?.absoluteString == "mailto:help@northstudio.example")
        #expect(rows.first { $0.title == "Сайт разработчика" }?.url?.absoluteString == "https://northstudio.example")
    }

    @Test func informationRowsWithoutLinksOrExtras() throws {
        let app = StoreApp(try detail { data in
            for key in Self.newKeys { data[key] = nil }
        })

        #expect(AppInfoRow.rows(for: app).map(\.title) == ["Продавец", "Категория", "Размер", "Совместимость", "Возраст", "Обновлено", "Версия"])
    }
}
