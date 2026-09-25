import Foundation

/// The native app's tokens (IMPLEMENTATION_PLAN D3).
nonisolated struct StoredTokens: Codable, Equatable, Sendable {
    let accessToken: String
    let accessTokenExpiresAt: Date
    let refreshToken: String
    let refreshTokenExpiresAt: Date

    init(accessToken: String, accessTokenExpiresAt: Date, refreshToken: String, refreshTokenExpiresAt: Date) {
        self.accessToken = accessToken
        self.accessTokenExpiresAt = accessTokenExpiresAt
        self.refreshToken = refreshToken
        self.refreshTokenExpiresAt = refreshTokenExpiresAt
    }

    init(_ claim: ClaimRedeemDTO) {
        self.init(
            accessToken: claim.accessToken,
            accessTokenExpiresAt: claim.accessTokenExpiresAt,
            refreshToken: claim.refreshToken,
            refreshTokenExpiresAt: claim.refreshTokenExpiresAt
        )
    }

    init(_ pair: TokenPairDTO) {
        self.init(
            accessToken: pair.accessToken,
            accessTokenExpiresAt: pair.accessTokenExpiresAt,
            refreshToken: pair.refreshToken,
            refreshTokenExpiresAt: pair.refreshTokenExpiresAt
        )
    }
}

nonisolated protocol TokenStore: Sendable {
    func load() -> StoredTokens?
    func save(_ tokens: StoredTokens?)
}

/// Tokens in the Keychain, one item per app install.
nonisolated struct KeychainTokenStore: TokenStore {
    private let keychain: KeychainStore
    private let account = "tokens"

    init(service: String = "storefront.session") {
        keychain = KeychainStore(service: service)
    }

    func load() -> StoredTokens? {
        keychain.data(for: account).flatMap { try? JSONDecoder().decode(StoredTokens.self, from: $0) }
    }

    func save(_ tokens: StoredTokens?) {
        if let tokens, let data = try? JSONEncoder().encode(tokens) {
            keychain.set(data, for: account)
        } else {
            keychain.remove(account)
        }
    }
}

/// For mock mode and tests.
nonisolated final class InMemoryTokenStore: TokenStore, @unchecked Sendable {
    private let lock = NSLock()
    private var tokens: StoredTokens?

    init(_ tokens: StoredTokens? = nil) {
        self.tokens = tokens
    }

    func load() -> StoredTokens? {
        lock.withLock { tokens }
    }

    func save(_ tokens: StoredTokens?) {
        lock.withLock { self.tokens = tokens }
    }
}
