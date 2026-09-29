/// The install CTA state for one app on this device. Always comes from the
/// backend; the app never infers installability locally (FULL_PLAN §4.2, §11).
nonisolated enum InstallState: Hashable, Sendable {
    /// No installable artifact exists.
    case unavailable
    /// An artifact exists, but this user or device cannot install it yet.
    case notEligible(reason: ErrorCode?)
    case get
    case preparing(progress: Double?)
    case readyToInstall
    /// The IPA was fully downloaded; iOS does not tell us whether the install finished, or whether
    /// the app was deleted since (IMPLEMENTATION_PLAN G13), so it can be installed again.
    case delivered
    case updateAvailable
    case failed(reason: ErrorCode?)

    init(_ dto: InstallStateDTO) {
        switch dto.status {
        case "not_eligible": self = .notEligible(reason: dto.reason)
        case "get": self = .get
        case "preparing": self = .preparing(progress: dto.progress)
        case "ready_to_install": self = .readyToInstall
        case "delivered": self = .delivered
        case "update_available": self = .updateAvailable
        case "failed": self = .failed(reason: dto.reason)
        // "unavailable" and states newer than this build: never offer an install.
        default: self = .unavailable
        }
    }

    /// Whether the CTA can start something (InstallationCoordinator.act).
    var isActionable: Bool {
        switch self {
        case .get, .readyToInstall, .delivered, .updateAvailable, .failed: true
        case .unavailable, .notEligible, .preparing: false
        }
    }
}
