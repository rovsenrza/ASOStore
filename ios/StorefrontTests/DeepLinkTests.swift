import Foundation
import Testing
@testable import Storefront

@Suite("Deep links")
struct DeepLinkTests {
    @Test(arguments: [
        ("storefront://claim?code=abc-123", DeepLink.claim(code: "abc-123")),
        ("STOREFRONT://Claim?code=XYZ", DeepLink.claim(code: "XYZ")),
        ("storefront://app/01m38ydb29rmtc8pfh0c7x5vkm", DeepLink.app(id: "01m38ydb29rmtc8pfh0c7x5vkm")),
    ])
    func parses(url: String, expected: DeepLink) {
        #expect(DeepLink(url: URL(string: url)!) == expected)
    }

    @Test(arguments: ["storefront://claim", "storefront://claim?code=", "storefront://app", "storefront://unknown", "https://example.com/claim?code=x"])
    func ignoresUnknownOrIncompleteLinks(url: String) {
        #expect(DeepLink(url: URL(string: url)!) == nil)
    }
}

@Suite("Claim sign-in")
struct ClaimSignInTests {
    @Test func redeemsTheCodeAndSignsIn() async throws {
        let transport = ScriptedTransport { request in
            let body = try JSONSerialization.jsonObject(with: request.httpBody!) as! [String: String]
            #expect(request.url!.path() == "/api/v1/storefront/claims/redeem")
            #expect(body["code"] == "one-time")
            return (201, try Fixtures.data("storefront-claim-redeem"))
        }
        let session = SessionStore(api: .stubbed(transport))
        let router = AppRouter(arguments: [])

        await router.handle(URL(string: "storefront://claim?code=one-time")!, session: session)

        #expect(router.selectedTab == .account)
        #expect(router.alertMessage == nil)
        #expect(session.user?.email == "anna@example.com")
        #expect(await session.api.authenticator.hasTokens)
    }

    @Test func explainsAnExpiredCode() async {
        let transport = ScriptedTransport { _ in (422, Envelopes.error("CLAIM_INVALID")) }
        let session = SessionStore(api: .stubbed(transport))
        let router = AppRouter(arguments: [])

        await router.handle(URL(string: "storefront://claim?code=stale")!, session: session)

        #expect(router.alertMessage?.contains("устарела") == true)
        #expect(session.user == nil)
    }

    @Test func decodesDeviceSummaries() async throws {
        let devices = try await APIClient.stubbed(StubTransport(body: Fixtures.data("devices-me"))).get("/devices/me", as: [DeviceSummaryDTO].self).data
        #expect(devices.first?.udidHint == "••••-6F70")
        #expect(devices.first?.registration?.status == "ELIGIBLE")

        let status = try await APIClient.stubbed(StubTransport(body: Fixtures.data("storefront-status-ready"))).get("/storefront/status", as: StorefrontStatusDTO.self).data
        #expect(status.stage == "storefront_ready")
        #expect(status.device?.family == "IPHONE")
    }
}
