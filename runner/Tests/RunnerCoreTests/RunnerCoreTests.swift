import Foundation
import Testing
@testable import RunnerCore

@Suite struct RequestSignerTests {
    // Vector produced by backend VerifyWorkerSignature::sign() with the same inputs.
    @Test func matchesTheBackendSignature() {
        let signer = RequestSigner(keyID: "rk_test", secret: "test-secret")
        let bodyHash = RequestSigner.sha256(Data("{}".utf8))

        #expect(bodyHash == "44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a")
        #expect(signer.signature(method: "post", path: "/api/worker/v1/heartbeat", timestamp: "1700000000", nonce: "nonce-abcdefghijklmnop", contentSHA256: bodyHash)
            == "40c2465d2e72608b4f2a710e5e01327c9f2aaf47c8a0f12b5cac382cbbcd0653")
    }

    @Test func sendsEveryHeaderTheBackendChecks() {
        let headers = RequestSigner(keyID: "rk_test", secret: "s").headers(method: "GET", path: "/p", contentSHA256: "abc", timestamp: 1, nonce: "n")
        #expect(Set(headers.keys) == ["X-Runner-Key", "X-Timestamp", "X-Nonce", "X-Content-SHA256", "X-Signature"])
        #expect(headers["X-Timestamp"] == "1")
    }
}

@Suite struct IdentityTests {
    @Test func parsesValidIdentitiesAndSkipsRevokedOnes() {
        let listing = """
          1) 32456AA8D13778DCF7D124470ED59E5795DD51DE "Apple Distribution: Example LLC (ABCDE12345)"
          2) B919DD862BB13F7661B1D958D20C570866A9C8B0 "Apple Development: dev@example.com (ZH9JSLQHBZ)" (CSSMERR_TP_CERT_REVOKED)
             2 valid identities found
        """
        let parsed = Identities.parseFindIdentity(listing)
        #expect(parsed.count == 1)
        #expect(parsed.first?.sha1 == "32456AA8D13778DCF7D124470ED59E5795DD51DE")
        #expect(parsed.first?.name == "Apple Distribution: Example LLC (ABCDE12345)")
    }

    @Test func readsTheTeamFromTheSubjectNotTheName() {
        let identity = Identities.parseCertificate(
            sha1: "32456AA8D13778DCF7D124470ED59E5795DD51DE",
            commonName: "Apple Development: dev@example.com (ZH9JSLQHBZ)",
            opensslOutput: """
            subject=UID=XYZ,CN=Apple Development: dev@example.com (ZH9JSLQHBZ),OU=ABCDE12345,O=Example LLC,C=US
            serial=7A1B2C3D4E5F
            notAfter=Sep 25 12:00:00 2027 GMT
            """
        )
        #expect(identity?.teamIdentifier == "ABCDE12345")
        #expect(identity?.serialNumber == "7A1B2C3D4E5F")
        #expect(identity?.expiresAt == "2027-09-25T12:00:00Z")
    }
}

@Suite struct EntitlementTests {
    private func profile(team: String) throws -> Data {
        try PropertyListSerialization.data(fromPropertyList: [
            "TeamIdentifier": [team],
            "Entitlements": ["application-identifier": "\(team).*", "get-task-allow": false, "keychain-access-groups": ["\(team).*"]],
        ], format: .xml, options: 0)
    }

    @Test func fixesTheApplicationIdentifierToTheLeasedBundle() throws {
        let entitlements = try Signer.entitlements(fromProfilePlist: profile(team: "ABCDE12345"), bundleIdentifier: "com.example.demo", teamIdentifier: "ABCDE12345")
        #expect(entitlements["application-identifier"] as? String == "ABCDE12345.com.example.demo")
        #expect(entitlements["com.apple.developer.team-identifier"] as? String == "ABCDE12345")
        #expect(entitlements["get-task-allow"] as? Bool == false)
    }

    @Test func refusesAProfileFromAnotherTeam() throws {
        #expect(throws: RunnerError.job(code: "TEAM_MISMATCH", message: "Profile belongs to [\"OTHER12345\"], lease says ABCDE12345", retryable: false)) {
            try Signer.entitlements(fromProfilePlist: profile(team: "OTHER12345"), bundleIdentifier: "com.example.demo", teamIdentifier: "ABCDE12345")
        }
    }
}

@Suite struct ConfigTests {
    @Test func requiresHTTPSUnlessExplicitlyAllowed() {
        let base = ["STOREFRONT_RUNNER_KEY_ID": "rk", "STOREFRONT_RUNNER_SECRET": "s"]
        #expect(throws: RunnerError.self) { try RunnerConfig.fromEnvironment(base.merging(["STOREFRONT_RUNNER_BASE_URL": "http://example.com"]) { $1 }) }
        #expect(throws: Never.self) { try RunnerConfig.fromEnvironment(base.merging(["STOREFRONT_RUNNER_BASE_URL": "https://example.com"]) { $1 }) }
    }
}
