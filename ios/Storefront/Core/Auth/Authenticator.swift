import Foundation

nonisolated enum SessionEvent: Equatable, Sendable {
    /// The refresh token was rejected; the user must sign in again.
    case expired
}

/// Owns the tokens and makes sure only one refresh runs at a time, however
/// many requests hit an expired access token together (IMPLEMENTATION_PLAN D3).
actor Authenticator {
    nonisolated let events: AsyncStream<SessionEvent>
    private let continuation: AsyncStream<SessionEvent>.Continuation
    private let store: any TokenStore
    private var tokens: StoredTokens?
    private var refreshTask: Task<StoredTokens, any Error>?

    init(store: any TokenStore) {
        self.store = store
        tokens = store.load()
        (events, continuation) = AsyncStream.makeStream(of: SessionEvent.self, bufferingPolicy: .bufferingNewest(1))
    }

    var hasTokens: Bool {
        tokens != nil
    }

    var accessToken: String? {
        tokens?.accessToken
    }

    func update(_ newTokens: StoredTokens?) {
        tokens = newTokens
        store.save(newTokens)
    }

    /// Returns usable tokens after `failedAccessToken` was rejected. If another
    /// request already refreshed, its result is reused instead of spending the
    /// refresh token twice (which the server would treat as token theft).
    func refresh(
        after failedAccessToken: String,
        using perform: @escaping @Sendable (String) async throws -> StoredTokens
    ) async throws -> StoredTokens {
        if let current = tokens, current.accessToken != failedAccessToken {
            return current
        }
        if let running = refreshTask {
            return try await running.value
        }
        guard let refreshToken = tokens?.refreshToken else {
            throw APIError(code: .sessionExpired)
        }

        // The task stores the outcome itself (it runs on this actor), so every
        // waiter resumes with the tokens already saved.
        let task = Task {
            defer { refreshTask = nil }
            do {
                let fresh = try await perform(refreshToken)
                update(fresh)
                return fresh
            } catch let error as APIError where error.code != .offline && error.code != .networkError {
                // The server rejected the refresh token: the session is over.
                update(nil)
                continuation.yield(.expired)
                throw APIError(code: .sessionExpired, message: error.message, status: error.status, requestID: error.requestID)
            }
        }
        refreshTask = task
        return try await task.value
    }
}
