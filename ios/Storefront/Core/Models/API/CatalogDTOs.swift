import Foundation

// Wire types for docs/api/openapi.yaml. Keys are converted from snake_case by
// APIClient. Keep these dumb: mapping to UI models happens elsewhere.

nonisolated struct HealthDTO: Codable, Equatable, Sendable {
    let status: String
    let version: String
    let time: Date
}

nonisolated struct CategoryRefDTO: Codable, Hashable, Sendable {
    let id: String
    let slug: String
    let title: String
    /// APPS or GAMES: which storefront tab the category belongs to.
    let kind: String?
}

nonisolated struct CategoryDTO: Codable, Hashable, Sendable {
    let id: String
    let slug: String
    let title: String
    let subtitle: String?
    let kind: String?
    let appCount: Int?
}

nonisolated struct PublisherRefDTO: Codable, Hashable, Sendable {
    let id: String
    let name: String
    let website: String?
}

nonisolated struct LatestVersionDTO: Codable, Hashable, Sendable {
    let version: String
    let buildNumber: String
    let minIosVersion: String?
    let releasedAt: Date?
    let sizeBytes: Int?
}

nonisolated struct InstallStateDTO: Codable, Hashable, Sendable {
    let status: String
    let reason: ErrorCode?
    let progress: Double?
    let installationId: String?
}

nonisolated struct AppSummaryDTO: Codable, Hashable, Sendable, Identifiable {
    let id: String
    let slug: String
    let name: String
    let subtitle: String?
    let category: CategoryRefDTO
    let publisher: PublisherRefDTO
    let iconUrl: URL?
    /// Banner for hero cards (the first screenshot), when there is one.
    let featureImageUrl: URL?
    let ageRating: String
    let latestVersion: LatestVersionDTO?
    let installState: InstallStateDTO
}

nonisolated struct ScreenshotDTO: Codable, Hashable, Sendable {
    let url: URL
    let width: Int
    let height: Int
}

nonisolated struct AppDetailDTO: Codable, Hashable, Sendable, Identifiable {
    let id: String
    let slug: String
    let name: String
    let subtitle: String?
    let category: CategoryRefDTO
    let publisher: PublisherRefDTO
    let iconUrl: URL?
    let featureImageUrl: URL?
    let ageRating: String
    let latestVersion: LatestVersionDTO?
    let installState: InstallStateDTO
    let description: String?
    let releaseNotes: String?
    let screenshots: [ScreenshotDTO]
    let supportUrl: String?
    let privacyUrl: String?
    // Added to `GET /apps/{id}` later: absent from older servers and from responses cached
    // before the update, so every one is optional and decoded leniently (see the extension).
    /// Distinct devices that installed the app.
    var downloads: Int?
    /// The app's public web page, for sharing. A string, not a URL: an empty or malformed
    /// value must not fail the whole page.
    var shareUrl: String?
    /// Same category, most downloaded first.
    var related: [AppSummaryDTO]?
    var moreFromPublisher: [AppSummaryDTO]?
    /// Newest first, a handful at most.
    var versions: [VersionDTO]?
}

nonisolated extension AppDetailDTO {
    /// The original fields decode strictly, as before. The newer ones are extras: a missing,
    /// null or malformed value becomes nil instead of failing the page (also when the response
    /// is re-read from the offline cache).
    init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        slug = try container.decode(String.self, forKey: .slug)
        name = try container.decode(String.self, forKey: .name)
        subtitle = try container.decodeIfPresent(String.self, forKey: .subtitle)
        category = try container.decode(CategoryRefDTO.self, forKey: .category)
        publisher = try container.decode(PublisherRefDTO.self, forKey: .publisher)
        iconUrl = try container.decodeIfPresent(URL.self, forKey: .iconUrl)
        featureImageUrl = try container.decodeIfPresent(URL.self, forKey: .featureImageUrl)
        ageRating = try container.decode(String.self, forKey: .ageRating)
        latestVersion = try container.decodeIfPresent(LatestVersionDTO.self, forKey: .latestVersion)
        installState = try container.decode(InstallStateDTO.self, forKey: .installState)
        description = try container.decodeIfPresent(String.self, forKey: .description)
        releaseNotes = try container.decodeIfPresent(String.self, forKey: .releaseNotes)
        screenshots = try container.decode([ScreenshotDTO].self, forKey: .screenshots)
        supportUrl = try container.decodeIfPresent(String.self, forKey: .supportUrl)
        privacyUrl = try container.decodeIfPresent(String.self, forKey: .privacyUrl)

        downloads = (try? container.decodeIfPresent(Int.self, forKey: .downloads)) ?? nil
        shareUrl = (try? container.decodeIfPresent(String.self, forKey: .shareUrl)) ?? nil
        related = ((try? container.decodeIfPresent(LossyList<AppSummaryDTO>.self, forKey: .related)) ?? nil)?.elements
        moreFromPublisher = ((try? container.decodeIfPresent(LossyList<AppSummaryDTO>.self, forKey: .moreFromPublisher)) ?? nil)?.elements
        versions = ((try? container.decodeIfPresent(LossyList<VersionDTO>.self, forKey: .versions)) ?? nil)?.elements
    }
}

/// An array that keeps the elements it can decode and drops the rest, so one bad entry in an
/// optional shelf does not take the page down with it.
nonisolated struct LossyList<Element: Decodable & Sendable>: Decodable, Sendable {
    let elements: [Element]

    init(from decoder: any Decoder) throws {
        var container = try decoder.unkeyedContainer()
        var elements: [Element] = []
        while !container.isAtEnd {
            if let element = try? container.decode(Element.self) {
                elements.append(element)
            } else if (try? container.decode(SkippedValue.self)) == nil {
                // A failed decode does not advance the container, so the bad element is stepped
                // over above; if even that fails, stop instead of spinning.
                break
            }
        }
        self.elements = elements
    }

    nonisolated private struct SkippedValue: Decodable {}
}

nonisolated struct VersionDTO: Codable, Hashable, Sendable, Identifiable {
    let id: String
    let version: String
    let buildNumber: String
    let releaseNotes: String?
    let minIosVersion: String?
    let releasedAt: Date?
}

nonisolated struct FeedDTO: Codable, Sendable {
    let sections: [FeedSectionDTO]
}

nonisolated struct FeedSectionDTO: Codable, Sendable, Identifiable {
    /// featured (first app is the hero), carousel, categories. Unknown kinds are skipped by the UI.
    let id: String
    let kind: String
    let title: String
    let apps: [AppSummaryDTO]?
    let categories: [CategoryDTO]?
}

nonisolated struct StorefrontStatusDTO: Codable, Equatable, Sendable {
    let stage: String
    let nextAction: String
    let blockingReason: ErrorCode?
    let device: DeviceSummaryDTO?
}

nonisolated struct RegistrationSummaryDTO: Codable, Hashable, Sendable {
    let status: String
    let reason: String?
    let updatedAt: Date?
}

/// A device as customers see it: never the UDID, only its last four characters.
nonisolated struct DeviceSummaryDTO: Codable, Hashable, Sendable, Identifiable {
    let id: String
    let product: String?
    let family: String
    let osVersion: String?
    let udidHint: String
    let enrolledAt: Date?
    let storefrontClaimed: Bool
    let registration: RegistrationSummaryDTO?
}
