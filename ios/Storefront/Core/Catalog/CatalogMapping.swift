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
        case "games": "gamecontroller.fill"
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
            description: detail.description ?? "",
            whatsNew: detail.releaseNotes ?? "",
            version: detail.latestVersion?.version ?? "",
            ageRating: detail.ageRating,
            size: Self.formattedSize(detail.latestVersion?.sizeBytes),
            systemImage: CategorySymbol.named(detail.category.slug),
            artwork: .derived(from: detail.id),
            installState: InstallState(detail.installState),
            iconURL: detail.iconUrl,
            screenshots: detail.screenshots.map(\.url),
            minIOSVersion: detail.latestVersion?.minIosVersion,
            supportURL: detail.supportUrl.flatMap(URL.init(string:)),
            privacyURL: detail.privacyUrl.flatMap(URL.init(string:))
        )
    }

    static func formattedSize(_ bytes: Int?) -> String {
        guard let bytes else { return "—" }
        return ByteCountFormatter.string(fromByteCount: Int64(bytes), countStyle: .file)
    }
}

extension StoreCategory {
    init(_ category: CategoryDTO) {
        self.init(
            id: category.slug,
            title: category.title,
            subtitle: category.subtitle ?? "",
            systemImage: CategorySymbol.named(category.slug),
            artwork: .derived(from: category.slug)
        )
    }
}
