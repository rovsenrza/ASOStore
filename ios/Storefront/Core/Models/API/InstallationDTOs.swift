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
    let status: InstallationStatus
    let statusReason: String?
    let app: AppRef
    let version: String?
    let buildNumber: String?
    let preparation: Preparation
    let deliveredAt: String?
    let updatedAt: String?

    /// Still moving on the server: worth polling and resuming after relaunch.
    var isActive: Bool {
        status.isActive
    }

    /// The CTA state this installation implies (same mapping as the backend resolver).
    var installState: InstallState {
        switch status {
        case .preparing: .preparing(progress: preparation.progress)
        case .readyToInstall, .authorized, .manifestFetched: .readyToInstall
        case .delivered: .delivered
        case .failed, .expired: .failed(reason: statusReason.map(ErrorCode.init(rawValue:)))
        case .unknown: .unavailable
        }
    }
}

/// POST /installations/{id}/authorize.
nonisolated struct InstallLinkDTO: Codable, Hashable, Sendable {
    let installationId: String
    let installUrl: URL
    let expiresAt: String
}
