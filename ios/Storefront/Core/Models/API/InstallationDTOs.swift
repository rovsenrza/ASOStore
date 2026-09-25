import Foundation

// Keys are decoded with APIClient's convertFromSnakeCase strategy.

/// GET /installations/{id}, POST /apps/{id}/prepare, GET /library items.
nonisolated struct InstallationDTO: Codable, Hashable, Sendable, Identifiable {
    struct AppRef: Codable, Hashable, Sendable {
        let id: String
        let name: String
        let iconUrl: URL?
    }

    struct Preparation: Codable, Hashable, Sendable {
        let stage: String?
        let progress: Double?
    }

    let id: String
    let status: String
    let statusReason: String?
    let app: AppRef
    let version: String?
    let buildNumber: String?
    let preparation: Preparation
    let deliveredAt: String?
    let updatedAt: String?

    /// Still moving on the server: worth polling and resuming after relaunch.
    var isActive: Bool {
        ["PREPARING", "READY_TO_INSTALL", "AUTHORIZED", "MANIFEST_FETCHED"].contains(status)
    }

    /// The CTA state this installation implies (same mapping as the backend resolver).
    var installState: InstallState {
        switch status {
        case "PREPARING": .preparing(progress: preparation.progress)
        case "READY_TO_INSTALL", "AUTHORIZED", "MANIFEST_FETCHED": .readyToInstall
        case "DELIVERED": .delivered
        case "FAILED", "EXPIRED": .failed(reason: statusReason.map(ErrorCode.init(rawValue:)))
        default: .unavailable
        }
    }
}

/// POST /installations/{id}/authorize.
nonisolated struct InstallLinkDTO: Codable, Hashable, Sendable {
    let installationId: String
    let installUrl: URL
    let expiresAt: String
}
