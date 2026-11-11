import Foundation

/// SF Symbol for a category slug, used for placeholder artwork.
enum CategorySymbol {
    static func named(_ slug: String) -> String {
        switch slug {
        case "productivity": "checkmark.circle.fill"
        case "photo-video": "camera.fill"
        case "health": "heart.fill"
        case "travel": "airplane"
        case "weather": "cloud.sun.fill"
        case "music": "music.note"
        case "business": "briefcase.fill"
        case "utilities": "wrench.and.screwdriver.fill"
        case "education": "graduationcap.fill"
        case "games", "games-arcade": "gamecontroller.fill"
        case "games-puzzle": "puzzlepiece.fill"
        case "games-racing": "car.fill"
        case "finance": "banknote.fill"
        case "social": "bubble.left.and.bubble.right.fill"
        default: "square.grid.2x2.fill"
        }
    }
}

extension StoreApp {
    init(_ summary: AppSummaryDTO) {
        self.init(
            id: summary.id,
            name: summary.name,
            subtitle: summary.subtitle ?? "",
            category: summary.category.title,
            developer: summary.publisher.name,
            version: summary.latestVersion?.version ?? "",
            ageRating: summary.ageRating,
            size: Self.formattedSize(summary.latestVersion?.sizeBytes),
            systemImage: CategorySymbol.named(summary.category.slug),
            artwork: .derived(from: summary.id),
            installState: InstallState(summary.installState),
            iconURL: summary.iconUrl,
            featureImageURL: summary.featureImageUrl,
            minIOSVersion: summary.latestVersion?.minIosVersion
        )
    }

    init(_ detail: AppDetailDTO) {
        self.init(
            id: detail.id,
            name: detail.name,
            subtitle: detail.subtitle ?? "",
            category: detail.category.title,
            developer: detail.publisher.name,
            description: (detail.description ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
            whatsNew: (detail.releaseNotes ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
            version: detail.latestVersion?.version ?? "",
            ageRating: detail.ageRating,
            size: Self.formattedSize(detail.latestVersion?.sizeBytes),
            systemImage: CategorySymbol.named(detail.category.slug),
            artwork: .derived(from: detail.id),
            installState: InstallState(detail.installState),
            iconURL: detail.iconUrl,
            featureImageURL: detail.featureImageUrl,
            screenshots: detail.screenshots.map(\.url),
            minIOSVersion: detail.latestVersion?.minIosVersion,
            supportURL: Self.link(detail.supportUrl, allowsMail: true),
            privacyURL: Self.link(detail.privacyUrl),
            publisherURL: Self.link(detail.publisher.website),
            buildNumber: detail.latestVersion?.buildNumber ?? "",
            updatedAt: detail.latestVersion?.releasedAt ?? detail.versions?.first?.releasedAt,
            downloads: detail.downloads.flatMap { $0 > 0 ? $0 : nil },
            shareURL: Self.link(detail.shareUrl),
            versions: (detail.versions ?? []).map(AppVersion.init),
            related: Self.shelf(detail.related, excluding: [detail.id])
        )
        // Nothing to call "the publisher" when the listing has none.
        if hasKnownDeveloper {
            moreFromPublisher = Self.shelf(detail.moreFromPublisher, excluding: Set(related.map(\.id)).union([detail.id]))
        }
        // The notes may arrive only inside the version list.
        if whatsNew.isEmpty, let latest = versions.first, latest.version == version {
            whatsNew = latest.notes
        }
    }

    /// A shelf on the app page: no app twice, and never one in `excluding` (the page's own app,
    /// or apps a shelf above already shows).
    static func shelf(_ summaries: [AppSummaryDTO]?, excluding: Set<String>) -> [StoreApp] {
        var seen = excluding
        return (summaries ?? []).compactMap { summary in
            seen.insert(summary.id).inserted ? StoreApp(summary) : nil
        }
    }

    /// A link the page can open: http(s), or a mail address for support. A bare domain gets
    /// https; anything else (empty, a phone number, free text) is dropped.
    static func link(_ string: String?, allowsMail: Bool = false) -> URL? {
        guard let text = string?.trimmingCharacters(in: .whitespacesAndNewlines), !text.isEmpty, !text.contains(" ") else { return nil }
        if let url = URL(string: text), let scheme = url.scheme?.lowercased() {
            switch scheme {
            case "http", "https": return (url.host() ?? "").isEmpty ? nil : url
            case "mailto": return allowsMail ? url : nil
            default: break
            }
        }
        if text.contains("@") {
            return allowsMail && !text.contains("/") && !text.contains(":") ? URL(string: "mailto:\(text)") : nil
        }
        guard text.contains("."), !text.contains(":"), let url = URL(string: "https://\(text)"), !(url.host() ?? "").isEmpty else { return nil }
        return url
    }

    static func formattedSize(_ bytes: Int?) -> String {
        guard let bytes else { return "—" }
        return ByteCountFormatter.string(fromByteCount: Int64(bytes), countStyle: .file)
    }
}

extension AppVersion {
    init(_ version: VersionDTO) {
        self.init(
            id: version.id,
            version: version.version,
            buildNumber: version.buildNumber,
            notes: (version.releaseNotes ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
            releasedAt: version.releasedAt
        )
    }
}

extension StoreCategory {
    init(_ category: CategoryDTO) {
        self.init(
            id: category.slug,
            title: category.title,
            subtitle: category.subtitle ?? "",
            systemImage: CategorySymbol.named(category.slug),
            artwork: .derived(from: category.slug),
            kind: category.kind,
            appCount: category.appCount
        )
    }
}
