import Foundation
import Testing
@testable import Storefront

/// The same fixtures are validated against docs/api/openapi.yaml by the backend
/// suite, so decoding them here keeps the iOS models in step with the contract.
@Suite("Shared API fixtures decode")
struct FixtureDecodingTests {
    private func decode<Payload: Decodable & Sendable>(_ fixture: String, as type: Payload.Type) async throws -> APIResponse<Payload> {
        try await APIClient.stubbed(StubTransport(body: Fixtures.data(fixture))).get("/any", as: type)
    }

    @Test func health() async throws {
        let response = try await decode("health", as: HealthDTO.self)
        #expect(response.data.status == "ok")
        #expect(response.meta.requestId != nil)
    }

    @Test func feed() async throws {
        let feed = try await decode("storefront-feed", as: FeedDTO.self).data

        #expect(feed.sections.map(\.id) == ["featured", "recently_updated", "categories"])
        let featured = try #require(feed.sections.first?.apps)
        #expect(featured.first?.name == "Focus Notes")
        #expect(featured.first?.latestVersion?.releasedAt != nil)
        #expect(feed.sections.last?.categories?.isEmpty == false)
    }

    @Test func appsListWithPagination() async throws {
        let response = try await decode("apps-list", as: [AppSummaryDTO].self)

        #expect(response.data.count == 9)
        #expect(response.meta.pagination == Pagination(page: 1, perPage: 50, total: 9, lastPage: 1))
    }

    @Test func appDetail() async throws {
        let app = try await decode("app-detail", as: AppDetailDTO.self).data

        #expect(app.slug == "focus-notes")
        #expect(app.description?.isEmpty == false)
        #expect(app.screenshots.isEmpty)
        #expect(InstallState(app.installState) == .get)
    }

    @Test func versions() async throws {
        let versions = try await decode("app-versions", as: [VersionDTO].self).data
        #expect(versions.first?.version == "3.4.0")
    }

    @Test func signedOutStatus() async throws {
        let status = try await decode("storefront-status-signed-out", as: StorefrontStatusDTO.self).data
        #expect(status.stage == "signed_out")
        #expect(status.nextAction == "sign_in")
        #expect(status.blockingReason == nil)
    }

    @Test func authTokens() async throws {
        let pair = try await decode("auth-tokens", as: TokenPairDTO.self).data

        #expect(pair.tokenType == "Bearer")
        #expect(pair.accessTokenExpiresAt < pair.refreshTokenExpiresAt)
        #expect(pair.user.subscription?.plan == "standard")
    }

    @Test func me() async throws {
        let me = try await decode("auth-me", as: MeDTO.self).data
        #expect(me.roles == ["customer"])
        #expect(me.subscription?.endsAt != nil)
    }

    @Test func deviceRequiredStatus() async throws {
        let status = try await decode("storefront-status-device-required", as: StorefrontStatusDTO.self).data
        #expect(status.stage == "device_required")
        #expect(status.nextAction == "enroll_device")
    }

    @Test func invalidCredentialsError() async throws {
        let transport = StubTransport(status: 422, body: try Fixtures.data("error-invalid-credentials"))
        let error = await #expect(throws: APIError.self) {
            try await APIClient.stubbed(transport).post("/auth/tokens", as: TokenPairDTO.self)
        }
        #expect(error?.code == .invalidCredentials)
    }

    @Test func everyFixtureInstallStateMapsToTheCTA() async throws {
        let apps = try await decode("apps-list", as: [AppSummaryDTO].self).data
        let states = Dictionary(uniqueKeysWithValues: apps.map { ($0.slug, InstallState($0.installState)) })

        #expect(states["focus-notes"] == .get)
        #expect(states["pixel-weather"] == .preparing(progress: 0.42))
        #expect(states["tempo"] == .readyToInstall)
        #expect(states["habit-garden"] == .updateAvailable)
        #expect(states["frame"] == .delivered)
        #expect(states["atlas"] == .notEligible(reason: .deviceNotEligible))
        #expect(states["orbit-mail"] == .failed(reason: .incompatibleDevice))
        #expect(states["paper-scan"] == .unavailable)
        #expect(states["lingua"] == .notEligible(reason: .unauthenticated))
    }
}
