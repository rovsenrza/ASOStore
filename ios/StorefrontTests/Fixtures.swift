import Foundation
@testable import Storefront

/// Loads the shared docs/api/examples fixtures bundled into this test target.
nonisolated enum Fixtures {
    private final class Token {}

    static func data(_ name: String) throws -> Data {
        let bundle = Bundle(for: Token.self)
        guard let url = bundle.url(forResource: name, withExtension: "json") else {
            throw CocoaError(.fileNoSuchFile, userInfo: [NSFilePathErrorKey: "\(name).json"])
        }
        return try Data(contentsOf: url)
    }
}

/// Transport that replays a canned response and records the request it saw.
nonisolated final class StubTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var _lastRequest: URLRequest?
    private let result: Result<(Data, Int, [String: String]), URLError>

    init(status: Int = 200, body: Data, headers: [String: String] = ["Content-Type": "application/json"]) {
        result = .success((body, status, headers))
    }

    init(error: URLError) {
        result = .failure(error)
    }

    var lastRequest: URLRequest? {
        lock.withLock { _lastRequest }
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        lock.withLock { _lastRequest = request }
        let (body, status, headers) = try result.get()
        return (body, HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: headers)!)
    }
}

/// Transport that answers each request with a handler, for multi-step flows.
nonisolated final class ScriptedTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var _requests: [URLRequest] = []
    private let handler: @Sendable (URLRequest) throws -> (Int, Data)

    init(handler: @escaping @Sendable (URLRequest) throws -> (Int, Data)) {
        self.handler = handler
    }

    var requests: [URLRequest] {
        lock.withLock { _requests }
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        lock.withLock { _requests.append(request) }
        // Let concurrent callers interleave, as real network calls would.
        try await Task.sleep(for: .milliseconds(20))
        let (status, body) = try handler(request)
        return (body, HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: ["Content-Type": "application/json"])!)
    }
}

nonisolated enum Envelopes {
    static func tokens(access: String, refresh: String) -> Data {
        Data("""
        {"data":{"token_type":"Bearer","access_token":"\(access)","access_token_expires_at":"2030-01-01T00:15:00Z",
        "refresh_token":"\(refresh)","refresh_token_expires_at":"2030-02-01T00:00:00Z",
        "user":{"id":"01m38ydb29rmtc8pfh0c7x5vkm","name":"Анна","email":"anna@example.com","roles":["customer"],"subscription":null,"created_at":"2026-09-25T10:00:00Z"}},
        "meta":{"request_id":"r"},"error":null}
        """.utf8)
    }

    static func error(_ code: String) -> Data {
        Data(#"{"data":null,"meta":{"request_id":"r"},"error":{"code":"\#(code)","message":"x","details":{}}}"#.utf8)
    }
}

extension StoredTokens {
    nonisolated static func sample(access: String = "access-1", refresh: String = "refresh-1") -> StoredTokens {
        StoredTokens(accessToken: access, accessTokenExpiresAt: .distantFuture, refreshToken: refresh, refreshTokenExpiresAt: .distantFuture)
    }
}

extension APIClient {
    static func stubbed(_ transport: any HTTPTransport, tokens: StoredTokens? = nil) -> APIClient {
        APIClient(
            environment: APIEnvironment(baseURL: URL(string: "https://api.test/api/v1")!, mode: .live),
            transport: transport,
            authenticator: Authenticator(store: InMemoryTokenStore(tokens))
        )
    }
}
