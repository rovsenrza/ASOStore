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
    let ageRating: String
    let latestVersion: LatestVersionDTO?
    let installState: InstallStateDTO
    let description: String?
    let releaseNotes: String?
    let screenshots: [ScreenshotDTO]
    let supportUrl: String?
    let privacyUrl: String?
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
