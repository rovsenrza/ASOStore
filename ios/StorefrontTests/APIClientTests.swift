import Foundation
import Testing
@testable import Storefront

@Suite("APIClient")
struct APIClientTests {
    @Test func sendsTracingAndContentHeaders() async throws {
        let transport = StubTransport(body: try Fixtures.data("health"))
        _ = try await APIClient.stubbed(transport).get("/health", query: [URLQueryItem(name: "q", value: "почта")], as: HealthDTO.self)

        let request = try #require(transport.lastRequest)
        #expect(request.url?.absoluteString == "https://api.test/api/v1/health?q=%D0%BF%D0%BE%D1%87%D1%82%D0%B0")
        #expect(request.value(forHTTPHeaderField: "Accept") == "application/json")
        #expect(request.value(forHTTPHeaderField: "X-Request-Id")?.hasPrefix("ios-") == true)
    }

    @Test func turnsErrorEnvelopesIntoAPIError() async throws {
        let transport = StubTransport(status: 404, body: try Fixtures.data("error-not-found"))

        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(transport).get("/apps/missing", as: AppDetailDTO.self)
        }
        #expect(error?.code == .notFound)
        #expect(error?.status == 404)
        #expect(error?.message.isEmpty == false)
        #expect(error?.requestID != nil)
    }

    @Test func exposesValidationFields() async throws {
        let transport = StubTransport(status: 422, body: try Fixtures.data("error-validation"))

        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(transport).get("/apps", as: [AppSummaryDTO].self)
        }
        #expect(error?.code == .validationFailed)
        #expect(error?.fields["per_page"]?.isEmpty == false)
    }

    @Test func keepsUnknownServerCodes() async throws {
        let body = Data(#"{"data":null,"meta":{"request_id":"r-1"},"error":{"code":"SOMETHING_NEW","message":"x","details":{}}}"#.utf8)

        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(StubTransport(status: 409, body: body)).get("/x", as: HealthDTO.self)
        }
        #expect(error?.code.rawValue == "SOMETHING_NEW")
        #expect(error?.requestID == "r-1")
    }

    @Test func reportsNonEnvelopeResponsesAsInvalid() async throws {
        let transport = StubTransport(status: 502, body: Data("<html>Bad gateway</html>".utf8), headers: ["X-Request-Id": "edge-1"])

        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(transport).get("/health", as: HealthDTO.self)
        }
        #expect(error?.code == .invalidResponse)
        #expect(error?.requestID == "edge-1")
    }

    @Test(arguments: [
        (URLError.Code.notConnectedToInternet, ErrorCode.offline),
        (URLError.Code.timedOut, ErrorCode.networkError),
    ])
    func mapsTransportFailures(urlError: URLError.Code, expected: ErrorCode) async throws {
        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(StubTransport(error: URLError(urlError))).get("/health", as: HealthDTO.self)
        }
        #expect(error?.code == expected)
    }

    /// iOS tears down sockets while the app is suspended; that is a network failure to retry,
    /// not the caller cancelling (which would silently stop an upload).
    @Test(arguments: [URLError(.cancelled) as any Error, NSError(domain: NSPOSIXErrorDomain, code: 53)])
    func treatsConnectionsTornDownBySuspensionAsNetworkFailures(failure: any Error) async throws {
        let transport = ScriptedTransport { _ in throw failure }
        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(transport).get("/health", as: HealthDTO.self)
        }
        #expect(error?.code == .networkError)
    }

    @Test func loadStateClassifiesFailures() {
        #expect(isCase(LoadState<Int>(error: APIError(code: .offline)), \.isOffline))
        #expect(isCase(LoadState<Int>(error: APIError(code: .unauthenticated)), \.isUnauthorized))
        #expect(isCase(LoadState<Int>(error: APIError(code: .sessionExpired)), \.isExpired))
        #expect(isCase(LoadState<Int>(error: CancellationError()), \.isFailed))
    }

    private func isCase(_ state: LoadState<Int>, _ check: KeyPath<LoadState<Int>, Bool>) -> Bool {
        state[keyPath: check]
    }
}

private extension LoadState {
    var isOffline: Bool { if case .offline = self { true } else { false } }
    var isUnauthorized: Bool { if case .unauthorized = self { true } else { false } }
    var isExpired: Bool { if case .expired = self { true } else { false } }
    var isFailed: Bool { if case .failed = self { true } else { false } }
}

@Suite("Mock transport routing")
struct MockTransportRoutingTests {
    @Test(arguments: [
        ("/api/v1/health", "health"),
        ("/api/v1/storefront/feed", "storefront-feed"),
        ("/api/v1/storefront/status", "storefront-status-signed-out"),
        ("/api/v1/apps", "apps-list"),
        ("/api/v1/apps/01m38ydb29rmtc8pfh0c7x5vkm", "app-detail"),
        ("/api/v1/apps/01m38ydb29rmtc8pfh0c7x5vkm/versions", "app-versions"),
    ])
    func routesToFixture(path: String, fixture: String) {
        #expect(MockTransport.fixture(method: "GET", path: path) == fixture)
    }

    @Test func unknownRoutesAndVerbsFallThrough() {
        #expect(MockTransport.fixture(method: "GET", path: "/api/v1/nope/deeper") == nil)
        #expect(MockTransport.fixture(method: "POST", path: "/api/v1/apps") == nil)
    }
}
