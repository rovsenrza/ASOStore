import Foundation
import OSLog

/// The app's only HTTP client (FULL_PLAN §11 "APIClient").
///
/// Decodes the `{data, meta, error}` envelope, turns failures into `APIError`,
/// tags every request with an `X-Request-Id` support can trace, attaches the
/// access token, and on a 401 refreshes once (through `Authenticator`) and retries.
actor APIClient {
    private static let logger = Logger(subsystem: "storefront", category: "api")

    let environment: APIEnvironment
    let authenticator: Authenticator
    private let transport: any HTTPTransport
    private let decoder: JSONDecoder
    private let encoder: JSONEncoder

    init(environment: APIEnvironment, transport: any HTTPTransport, authenticator: Authenticator) {
        self.environment = environment
        self.transport = transport
        self.authenticator = authenticator
        decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        decoder.dateDecodingStrategy = .iso8601
        encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
    }

    /// Client for the configured environment: live network with Keychain tokens,
    /// or bundled fixtures with in-memory tokens in Debug mock mode.
    static func configured(environment: APIEnvironment = .current()) -> APIClient {
        #if DEBUG
        if environment.mode == .mock {
            return APIClient(environment: environment, transport: MockTransport(), authenticator: Authenticator(store: InMemoryTokenStore()))
        }
        #endif
        return APIClient(environment: environment, transport: URLSessionTransport(), authenticator: Authenticator(store: KeychainTokenStore()))
    }

    func get<Payload: Decodable & Sendable>(
        _ path: String,
        query: [URLQueryItem] = [],
        as type: Payload.Type = Payload.self
    ) async throws -> APIResponse<Payload> {
        try await send(method: "GET", path: path, query: query, body: nil)
    }

    func post<Payload: Decodable & Sendable>(
        _ path: String,
        body: some Encodable & Sendable,
        as type: Payload.Type = Payload.self
    ) async throws -> APIResponse<Payload> {
        try await send(method: "POST", path: path, query: [], body: try encoder.encode(body))
    }

    func post<Payload: Decodable & Sendable>(
        _ path: String,
        as type: Payload.Type = Payload.self
    ) async throws -> APIResponse<Payload> {
        try await send(method: "POST", path: path, query: [], body: nil)
    }

    /// Raw bytes (an upload chunk), with extra headers and a longer timeout for slow uplinks.
    func put<Payload: Decodable & Sendable>(
        _ path: String,
        data: Data,
        headers: [String: String] = [:],
        as type: Payload.Type = Payload.self
    ) async throws -> APIResponse<Payload> {
        try await send(method: "PUT", path: path, query: [], body: data, contentType: "application/octet-stream", headers: headers, timeout: 180)
    }

    func delete<Payload: Decodable & Sendable>(
        _ path: String,
        as type: Payload.Type = Payload.self
    ) async throws -> APIResponse<Payload> {
        try await send(method: "DELETE", path: path, query: [], body: nil)
    }

    private func send<Payload: Decodable & Sendable>(
        method: String,
        path: String,
        query: [URLQueryItem],
        body: Data?,
        contentType: String = "application/json",
        headers: [String: String] = [:],
        timeout: TimeInterval = 30,
        retryToken: String? = nil
    ) async throws -> APIResponse<Payload> {
        let isRetry = retryToken != nil
        let token: String? = if let retryToken { retryToken } else { await authenticator.accessToken }
        var request = makeRequest(method: method, path: path, query: query, body: body, contentType: contentType, timeout: timeout)
        for (name, value) in headers {
            request.setValue(value, forHTTPHeaderField: name)
        }
        if let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        do {
            return try await perform(request)
        } catch let error as APIError where error.status == 401 && token != nil && !isRetry {
            let fresh = try await authenticator.refresh(after: token!) { refreshToken in
                try await self.refreshTokens(refreshToken)
            }
            return try await send(
                method: method, path: path, query: query, body: body,
                contentType: contentType, headers: headers, timeout: timeout, retryToken: fresh.accessToken
            )
        }
    }

    private func refreshTokens(_ refreshToken: String) async throws -> StoredTokens {
        let body = try encoder.encode(RefreshRequest(refreshToken: refreshToken))
        let response: APIResponse<TokenPairDTO> = try await perform(makeRequest(method: "POST", path: "/auth/refresh", query: [], body: body))
        return StoredTokens(response.data)
    }

    private func perform<Payload: Decodable & Sendable>(_ request: URLRequest) async throws -> APIResponse<Payload> {
        let requestID = request.value(forHTTPHeaderField: "X-Request-Id")
        let label = "\(request.httpMethod ?? "?") \(request.url?.path() ?? "?")"

        let data: Data
        let response: HTTPURLResponse
        do {
            (data, response) = try await transport.send(request)
        } catch is CancellationError {
            throw CancellationError()
        } catch let error as URLError where error.code == .cancelled && Task.isCancelled {
            throw CancellationError()
        } catch let error as URLError {
            let offline: Set<URLError.Code> = [.notConnectedToInternet, .networkConnectionLost, .dataNotAllowed]
            throw APIError(code: offline.contains(error.code) ? .offline : .networkError, requestID: requestID)
        } catch {
            // A connection iOS tore down while the app was suspended can surface as a POSIX error
            // ("Software caused connection abort") rather than a URLError: still a network failure.
            throw APIError(code: .networkError, requestID: requestID)
        }

        let responseID = response.value(forHTTPHeaderField: "X-Request-Id") ?? requestID

        if (200..<300).contains(response.statusCode) {
            do {
                let envelope = try decoder.decode(APIEnvelope<Payload>.self, from: data)
                return APIResponse(data: envelope.data, meta: envelope.meta)
            } catch {
                Self.logger.error("Undecodable \(label, privacy: .public) response, request \(responseID ?? "-", privacy: .public)")
                throw APIError(code: .invalidResponse, status: response.statusCode, requestID: responseID)
            }
        }

        guard let failure = try? decoder.decode(APIErrorEnvelope.self, from: data) else {
            throw APIError(code: .invalidResponse, status: response.statusCode, requestID: responseID)
        }

        throw APIError(
            code: failure.error.code,
            message: failure.error.message,
            status: response.statusCode,
            requestID: failure.meta?.requestId ?? responseID,
            fields: failure.error.details?.fields ?? [:]
        )
    }

    private func makeRequest(
        method: String,
        path: String,
        query: [URLQueryItem],
        body: Data?,
        contentType: String = "application/json",
        timeout: TimeInterval = 30
    ) -> URLRequest {
        var components = URLComponents(
            url: environment.baseURL.appending(path: path.trimmingPrefix("/")),
            resolvingAgainstBaseURL: false
        )!
        if !query.isEmpty {
            components.queryItems = query
        }

        var request = URLRequest(url: components.url!)
        request.httpMethod = method
        request.timeoutInterval = timeout
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("ru", forHTTPHeaderField: "Accept-Language")
        request.setValue("ios-\(UUID().uuidString.lowercased())", forHTTPHeaderField: "X-Request-Id")
        if let build = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String {
            request.setValue(build, forHTTPHeaderField: "X-App-Build")
        }
        if let body {
            request.httpBody = body
            request.setValue(contentType, forHTTPHeaderField: "Content-Type")
        }
        return request
    }
}
