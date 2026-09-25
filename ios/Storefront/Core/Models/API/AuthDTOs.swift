import Foundation

// Wire types for the auth endpoints in docs/api/openapi.yaml.

nonisolated struct SubscriptionDTO: Decodable, Hashable, Sendable {
    let id: String
    let plan: String
    let status: String
    let startsAt: Date
    let endsAt: Date?
}

nonisolated struct MeDTO: Decodable, Hashable, Sendable, Identifiable {
    let id: String
    let name: String
    let email: String
    let roles: [String]
    let subscription: SubscriptionDTO?
    let createdAt: Date
}

nonisolated struct TokenPairDTO: Decodable, Sendable {
    let tokenType: String
    let accessToken: String
    let accessTokenExpiresAt: Date
    let refreshToken: String
    let refreshTokenExpiresAt: Date
    let user: MeDTO
}

nonisolated struct TokenRequest: Encodable, Sendable {
    let email: String
    let password: String
    let deviceName: String
}

nonisolated struct RefreshRequest: Encodable, Sendable {
    let refreshToken: String
}

/// POST /storefront/claims/redeem: tokens bound to the enrolled device.
nonisolated struct ClaimRedeemDTO: Decodable, Sendable {
    let tokenType: String
    let accessToken: String
    let accessTokenExpiresAt: Date
    let refreshToken: String
    let refreshTokenExpiresAt: Date
    let user: MeDTO
    let device: DeviceSummaryDTO
}

nonisolated struct ClaimRedeemRequest: Encodable, Sendable {
    let code: String
    let deviceName: String
}

/// For responses whose `data` is null.
nonisolated struct NoContent: Decodable, Sendable {}
