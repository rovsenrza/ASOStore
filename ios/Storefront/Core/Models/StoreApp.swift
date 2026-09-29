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
}

extension StoreApp {
    /// Only the ID is known (a link): the app page fills in the rest from the API.
    static func loading(id: String) -> StoreApp {
        StoreApp(id: id, name: "", subtitle: "", category: "", developer: "", systemImage: "app.fill", artwork: .derived(from: id), installState: .unavailable)
    }
}
