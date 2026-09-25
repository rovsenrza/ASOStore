import Foundation
import Testing
@testable import Storefront

@Suite("Authenticator")
struct AuthenticatorTests {
    actor Counter {
        private(set) var value = 0
        func increment() -> Int { value += 1; return value }
    }

    @Test func concurrentRefreshesSpendTheRefreshTokenOnce() async throws {
        let authenticator = Authenticator(store: InMemoryTokenStore(.sample()))
        let counter = Counter()

        let results = try await withThrowingTaskGroup(of: StoredTokens.self) { group in
            for _ in 0..<5 {
                group.addTask {
                    try await authenticator.refresh(after: "access-1") { refreshToken in
                        #expect(refreshToken == "refresh-1")
                        let call = await counter.increment()
                        try await Task.sleep(for: .milliseconds(50))
                        return .sample(access: "access-\(call + 1)", refresh: "refresh-\(call + 1)")
                    }
                }
            }
            var collected: [StoredTokens] = []
            for try await tokens in group {
                collected.append(tokens)
            }
            return collected
        }

        #expect(await counter.value == 1)
        #expect(Set(results.map(\.accessToken)) == ["access-2"])
        #expect(await authenticator.accessToken == "access-2")
    }

    @Test func reusesTokensAlreadyRefreshedByAnotherRequest() async throws {
        let authenticator = Authenticator(store: InMemoryTokenStore(.sample(access: "access-2", refresh: "refresh-2")))

        let tokens = try await authenticator.refresh(after: "access-1") { _ in
            Issue.record("A stale failure must not trigger another refresh")
            return .sample()
        }

        #expect(tokens.accessToken == "access-2")
    }

    @Test func rejectedRefreshEndsTheSession() async throws {
        let store = InMemoryTokenStore(.sample())
        let authenticator = Authenticator(store: store)
        var events = authenticator.events.makeAsyncIterator()

        let error = await #expect(throws: APIError.self) {
            try await authenticator.refresh(after: "access-1") { _ in throw APIError(code: .sessionExpired, status: 401) }
        }

        #expect(error?.code == .sessionExpired)
        #expect(store.load() == nil)
        #expect(await events.next() == .expired)
    }

    @Test func offlineRefreshKeepsTheSession() async throws {
        let store = InMemoryTokenStore(.sample())
        let authenticator = Authenticator(store: store)

        let error = await #expect(throws: APIError.self) {
            try await authenticator.refresh(after: "access-1") { _ in throw APIError(code: .offline) }
        }

        #expect(error?.code == .offline)
        #expect(store.load() == .sample())
    }
}

@Suite("APIClient authentication")
struct APIClientAuthTests {
    @Test func attachesTheAccessToken() async throws {
        let transport = ScriptedTransport { _ in (200, try Fixtures.data("auth-me")) }
        _ = try await APIClient.stubbed(transport, tokens: .sample()).get("/auth/me", as: MeDTO.self)

        #expect(transport.requests.first?.value(forHTTPHeaderField: "Authorization") == "Bearer access-1")
    }

    @Test func refreshesOnceAndRetriesAfterA401() async throws {
        let transport = ScriptedTransport { request in
            switch (request.url!.path(), request.value(forHTTPHeaderField: "Authorization")) {
            case ("/api/v1/auth/refresh", _):
                let body = try JSONSerialization.jsonObject(with: request.httpBody!) as! [String: String]
                #expect(body == ["refresh_token": "refresh-1"])
                return (200, Envelopes.tokens(access: "access-2", refresh: "refresh-2"))
            case (_, "Bearer access-2"):
                return (200, try Fixtures.data("auth-me"))
            default:
                return (401, Envelopes.error("UNAUTHENTICATED"))
            }
        }
        let client = APIClient.stubbed(transport, tokens: .sample())

        async let first = client.get("/auth/me", as: MeDTO.self)
        async let second = client.get("/auth/me", as: MeDTO.self)
        _ = try await (first, second)

        let refreshes = transport.requests.filter { $0.url!.path().hasSuffix("/auth/refresh") }
        #expect(refreshes.count == 1)
        #expect(refreshes.first?.value(forHTTPHeaderField: "Authorization") == nil)
        #expect(await client.authenticator.accessToken == "access-2")
    }

    @Test func reportsAnExpiredSessionWhenTheRefreshIsRejected() async throws {
        let transport = ScriptedTransport { request in
            request.url!.path().hasSuffix("/auth/refresh")
                ? (401, Envelopes.error("SESSION_EXPIRED"))
                : (401, Envelopes.error("UNAUTHENTICATED"))
        }
        let client = APIClient.stubbed(transport, tokens: .sample())

        let error = await #expect(throws: APIError.self) {
            try await client.get("/auth/me", as: MeDTO.self)
        }

        #expect(error?.code == .sessionExpired)
        #expect(await client.authenticator.hasTokens == false)
        #expect(transport.requests.count == 2)
    }

    @Test func doesNotRefreshWithoutASession() async throws {
        let transport = ScriptedTransport { _ in (401, Envelopes.error("UNAUTHENTICATED")) }

        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(transport).get("/auth/me", as: MeDTO.self)
        }

        #expect(error?.code == .unauthenticated)
        #expect(transport.requests.count == 1)
    }

    @Test func encodesRequestBodiesInSnakeCase() async throws {
        let transport = ScriptedTransport { _ in (201, Envelopes.tokens(access: "a", refresh: "r")) }
        _ = try await APIClient.stubbed(transport).post(
            "/auth/tokens",
            body: TokenRequest(email: "anna@example.com", password: "secret", deviceName: "iPhone"),
            as: TokenPairDTO.self
        )

        let request = try #require(transport.requests.first)
        let body = try JSONSerialization.jsonObject(with: request.httpBody!) as! [String: String]
        #expect(body == ["email": "anna@example.com", "password": "secret", "device_name": "iPhone"])
        #expect(request.value(forHTTPHeaderField: "Content-Type") == "application/json")
    }
}

@Suite("Session store")
struct SessionStoreTests {
    @Test func restoresToSignedOutWithoutTokens() async {
        let session = SessionStore(api: .stubbed(ScriptedTransport { _ in (500, Data()) }))
        await session.restore()
        #expect(session.state == .signedOut)
    }

    @Test func staysUnverifiedWhenOfflineAtLaunch() async {
        let transport = ScriptedTransport { _ in throw URLError(.notConnectedToInternet) }
        let session = SessionStore(api: .stubbed(transport, tokens: .sample()))

        await session.restore()

        #expect(session.state == .unverified)
        #expect(await session.api.authenticator.hasTokens)
    }

    @Test func signsInStoresTokensAndSignsOut() async throws {
        let transport = ScriptedTransport { request in
            request.url!.path().hasSuffix("/auth/tokens")
                ? (201, Envelopes.tokens(access: "access-9", refresh: "refresh-9"))
                : (200, Data(#"{"data":null,"meta":{"request_id":"r"},"error":null}"#.utf8))
        }
        let session = SessionStore(api: .stubbed(transport))

        try await session.signIn(email: " anna@example.com ", password: "secret")
        #expect(session.user?.email == "anna@example.com")
        #expect(await session.api.authenticator.accessToken == "access-9")

        await session.signOut()
        #expect(session.state == .signedOut)
        #expect(await session.api.authenticator.hasTokens == false)
        #expect(transport.requests.last?.value(forHTTPHeaderField: "Authorization") == "Bearer access-9")
    }
}

@Suite("Keychain token store")
struct KeychainTokenStoreTests {
    @Test func roundTripsAndDeletes() {
        let store = KeychainTokenStore(service: "storefront.tests.\(UUID().uuidString)")
        #expect(store.load() == nil)

        store.save(.sample())
        #expect(store.load() == .sample())

        store.save(.sample(access: "access-2"))
        #expect(store.load()?.accessToken == "access-2")

        store.save(nil)
        #expect(store.load() == nil)
    }
}
