/// Server status of one installation (backend `InstallationStatus`).
///
/// An open set: a status added on the server after this build still decodes, as `.unknown`,
/// and is treated as finished (never polled, never offered as an install).
nonisolated enum InstallationStatus: RawRepresentable, Hashable, Sendable, Codable {
    case preparing
    case readyToInstall
    case authorized
    case manifestFetched
    case delivered
    case failed
    case expired
    case unknown(String)

    init(rawValue: String) {
        switch rawValue {
        case "PREPARING": self = .preparing
        case "READY_TO_INSTALL": self = .readyToInstall
        case "AUTHORIZED": self = .authorized
        case "MANIFEST_FETCHED": self = .manifestFetched
        case "DELIVERED": self = .delivered
        case "FAILED": self = .failed
        case "EXPIRED": self = .expired
        default: self = .unknown(rawValue)
        }
    }

    var rawValue: String {
        switch self {
        case .preparing: "PREPARING"
        case .readyToInstall: "READY_TO_INSTALL"
        case .authorized: "AUTHORIZED"
        case .manifestFetched: "MANIFEST_FETCHED"
        case .delivered: "DELIVERED"
        case .failed: "FAILED"
        case .expired: "EXPIRED"
        case .unknown(let value): value
        }
    }

    init(from decoder: Decoder) throws {
        self.init(rawValue: try decoder.singleValueContainer().decode(String.self))
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(rawValue)
    }

    /// Still moving on the server: worth remembering across a relaunch.
    var isActive: Bool {
        switch self {
        case .preparing, .readyToInstall, .authorized, .manifestFetched: true
        case .delivered, .failed, .expired, .unknown: false
        }
    }

    /// The server will move this on by itself, so the app polls it.
    var isPolled: Bool {
        switch self {
        case .preparing, .authorized, .manifestFetched: true
        case .readyToInstall, .delivered, .failed, .expired, .unknown: false
        }
    }
}
