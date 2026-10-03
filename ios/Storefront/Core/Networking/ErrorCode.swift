/// Stable API error code (IMPLEMENTATION_PLAN §5.2, docs/api/openapi.yaml).
///
/// An open set: codes added on the server after this build still decode, and
/// UI code treats unknown codes like `.internal`.
nonisolated struct ErrorCode: RawRepresentable, Hashable, Sendable, Codable {
    let rawValue: String

    init(rawValue: String) {
        self.rawValue = rawValue
    }

    init(from decoder: Decoder) throws {
        rawValue = try decoder.singleValueContainer().decode(String.self)
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(rawValue)
    }

    // Server codes used by the app.
    static let validationFailed = ErrorCode(rawValue: "VALIDATION_FAILED")
    static let unauthenticated = ErrorCode(rawValue: "UNAUTHENTICATED")
    static let sessionExpired = ErrorCode(rawValue: "SESSION_EXPIRED")
    static let invalidCredentials = ErrorCode(rawValue: "INVALID_CREDENTIALS")
    static let accountSuspended = ErrorCode(rawValue: "ACCOUNT_SUSPENDED")
    static let activationInvalid = ErrorCode(rawValue: "ACTIVATION_INVALID")
    static let claimInvalid = ErrorCode(rawValue: "CLAIM_INVALID")
    static let activationAlreadyUsed = ErrorCode(rawValue: "ACTIVATION_ALREADY_USED")
    static let forbidden = ErrorCode(rawValue: "FORBIDDEN")
    static let notFound = ErrorCode(rawValue: "NOT_FOUND")
    static let conflict = ErrorCode(rawValue: "CONFLICT")
    static let rateLimited = ErrorCode(rawValue: "RATE_LIMITED")
    static let deviceNotEligible = ErrorCode(rawValue: "DEVICE_NOT_ELIGIBLE")
    static let devicePendingApple = ErrorCode(rawValue: "DEVICE_PENDING_APPLE")
    static let quotaExhausted = ErrorCode(rawValue: "QUOTA_EXHAUSTED")
    static let noEligibleTeam = ErrorCode(rawValue: "NO_ELIGIBLE_TEAM")
    static let artifactNotInstallable = ErrorCode(rawValue: "ARTIFACT_NOT_INSTALLABLE")
    static let incompatibleDevice = ErrorCode(rawValue: "INCOMPATIBLE_DEVICE")
    static let installTokenExpired = ErrorCode(rawValue: "INSTALL_TOKEN_EXPIRED")
    static let serviceUnavailable = ErrorCode(rawValue: "SERVICE_UNAVAILABLE")
    static let `internal` = ErrorCode(rawValue: "INTERNAL")

    // Client-only codes.
    static let offline = ErrorCode(rawValue: "OFFLINE")
    static let networkError = ErrorCode(rawValue: "NETWORK_ERROR")
    static let invalidResponse = ErrorCode(rawValue: "INVALID_RESPONSE")
}
