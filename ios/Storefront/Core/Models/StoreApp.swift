import Foundation

/// What the catalog views render. Built from API data (CatalogMapping.swift),
/// or from MockCatalog in previews.
struct StoreApp: Identifiable, Hashable {
    let id: String
    let name: String
    let subtitle: String
    let category: String
    let developer: String
    var description: String = ""
    var whatsNew: String = ""
    var version: String = ""
    var ageRating: String = "4+"
    var size: String = "—"
    /// Placeholder symbol when there is no icon image.
    let systemImage: String
    let artwork: AppArtwork
    /// Titles of illustrative screenshots (previews only).
    var screenshotTitles: [String] = []
    /// Always backend-provided; drives the CTA (FULL_PLAN §11).
    let installState: InstallState
    var iconURL: URL?
    /// Hero card banner; placeholder artwork when nil.
    var featureImageURL: URL?
    var screenshots: [URL] = []
    var minIOSVersion: String?
    var supportURL: URL?
    var privacyURL: URL?
    var publisherURL: URL?
    /// Build of the latest version, shown next to it in «Информация».
    var buildNumber: String = ""
    /// When the latest version was released.
    var updatedAt: Date?
    /// Distinct devices that installed the app; nil when the server does not say (or says zero).
    var downloads: Int?
    /// The app's public web page, for sharing.
    var shareURL: URL?
    /// Released versions, newest first (the app page's «История версий»).
    var versions: [AppVersion] = []
    /// «Похожие приложения»: same category, most downloaded first.
    var related: [StoreApp] = []
    /// «Ещё от издателя».
    var moreFromPublisher: [StoreApp] = []
}

extension StoreApp {
    /// What the catalog publishes a listing under when its developer is unknown.
    static let unknownDeveloper = "Разработчик не указан"

    /// Nothing but the ID is known yet (a page opened from a link): draw placeholders, not blank text.
    var isPlaceholder: Bool { name.isEmpty }

    var hasKnownDeveloper: Bool {
        !developer.isEmpty && developer != Self.unknownDeveloper
    }

    /// Versions before the one the page already describes under «Что нового».
    var earlierVersions: [AppVersion] {
        guard let first = versions.first, first.version == version else { return versions }
        return Array(versions.dropFirst())
    }

    /// Only the ID is known (a link): the app page fills in the rest from the API.
    static func loading(id: String) -> StoreApp {
        StoreApp(id: id, name: "", subtitle: "", category: "", developer: "", systemImage: "app.fill", artwork: .derived(from: id), installState: .unavailable)
    }
}
