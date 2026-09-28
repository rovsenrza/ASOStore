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
    @Test func readsTheTeamFromTheSubjectNotTheName() {
        let identity = Identities.parseCertificate(opensslOutput: """
            sha1 Fingerprint=32:45:6A:A8:D1:37:78:DC:F7:D1:24:47:0E:D5:9E:57:95:DD:51:DE
            serial=7A1B2C3D4E5F
            notAfter=Sep 25 12:00:00 2027 GMT
            subject=
                userId                    = XYZ
                commonName                = Apple Distribution: Example, LLC (ZH9JSLQHBZ)
                organizationalUnitName    = ABCDE12345
                organizationName          = Example, LLC
                countryName               = US
            """)
        #expect(identity?.sha1 == "32456AA8D13778DCF7D124470ED59E5795DD51DE")
        #expect(identity?.teamIdentifier == "ABCDE12345")
        #expect(identity?.commonName == "Apple Distribution: Example, LLC (ZH9JSLQHBZ)")
        #expect(identity?.serialNumber == "7A1B2C3D4E5F")
        #expect(identity?.expiresAt == "2027-09-25T12:00:00Z")
    }

    @Test func skipsACertificateWithoutATeam() {
        #expect(Identities.parseCertificate(opensslOutput: "sha1 Fingerprint=32:45:6A:A8:D1:37:78:DC:F7:D1:24:47:0E:D5:9E:57:95:DD:51:DE\nserial=01\n") == nil)
    }

    @Test(.enabled(if: Tools.available("openssl")))
    func pairsKeysWithCertificatesAndSkipsMismatches() throws {
        let folder = try TestIdentity.make()
        defer { try? FileManager.default.removeItem(at: folder.root) }
        // A second certificate whose key file belongs to someone else.
        try Tools.openssl(["genrsa", "-out", folder.identities.appendingPathComponent("stray.key").path, "2048"])
        try FileManager.default.copyItem(at: folder.identities.appendingPathComponent("test.cer"), to: folder.identities.appendingPathComponent("stray.cer"))

        let loaded = try Identities.load(directory: folder.identities)
        #expect(loaded.map(\.identity.teamIdentifier) == ["TESTTEAM01"])
        #expect(loaded.first?.identity.commonName == "Apple Distribution: Runner Test (TESTTEAM01)")
        #expect(loaded.first?.identity.sha1.count == 40)
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

    @Test func readsThePlistOutOfTheCMSEnvelope() throws {
        let envelope = Data([0x30, 0x82, 0x01]) + (try profile(team: "ABCDE12345")) + Data([0xA0, 0x00])
        let entitlements = try Signer.entitlements(fromProfile: envelope, bundleIdentifier: "com.example.demo", teamIdentifier: "ABCDE12345")
        #expect(entitlements["application-identifier"] as? String == "ABCDE12345.com.example.demo")
    }

    @Test func refusesAProfileFromAnotherTeam() throws {
        #expect(throws: RunnerError.job(code: "TEAM_MISMATCH", message: "Profile belongs to [\"OTHER12345\"], lease says ABCDE12345", retryable: false)) {
            try Signer.entitlements(fromProfilePlist: profile(team: "OTHER12345"), bundleIdentifier: "com.example.demo", teamIdentifier: "ABCDE12345")
        }
    }
}

@Suite struct ConfigTests {
    private let base = ["STOREFRONT_RUNNER_KEY_ID": "rk", "STOREFRONT_RUNNER_SECRET": "s", "STOREFRONT_RUNNER_IDENTITIES_DIR": "/run/secrets/signing"]

    @Test func requiresHTTPSUnlessExplicitlyAllowed() {
        #expect(throws: RunnerError.self) { try RunnerConfig.fromEnvironment(base.merging(["STOREFRONT_RUNNER_BASE_URL": "http://example.com"]) { $1 }) }
        #expect(throws: Never.self) { try RunnerConfig.fromEnvironment(base.merging(["STOREFRONT_RUNNER_BASE_URL": "https://example.com"]) { $1 }) }
    }

    @Test func requiresAnIdentitiesFolder() {
        var env = base.merging(["STOREFRONT_RUNNER_BASE_URL": "https://example.com"]) { $1 }
        env.removeValue(forKey: "STOREFRONT_RUNNER_IDENTITIES_DIR")
        #expect(throws: RunnerError.configuration("STOREFRONT_RUNNER_IDENTITIES_DIR is not set")) { try RunnerConfig.fromEnvironment(env) }
    }
}

@Suite struct ZsignOutputTests {
    @Test func keepsTheFailureLinesWithoutColours() {
        let output = "\u{1B}[0m>>> Signing: \t/tmp/x/DemoApp.app ...\n\u{1B}[31m>>> Build CMS signature failed!\n\u{1B}[0m>>> Done. (0.010s)\n"
        #expect(Signer.zsignError(output) == ">>> Build CMS signature failed!")
    }
}

/// End to end on Linux (and on a Mac with zsign installed): sign the unsigned
/// DemoApp fixture with a throwaway identity, then check the result the way the
/// runner does before uploading.
@Suite(.enabled(if: Tools.available("zsign") && Tools.available("openssl") && Tools.available("unzip")))
struct SigningTests {
    @Test func signsTheFixtureAndTheResultVerifies() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }

        let output = try Signer().sign(job: setup.job, identity: setup.identity, source: setup.source, workDirectory: setup.work)

        #expect(output.sha256 == (try RequestSigner.sha256(fileAt: output.ipa)))
        let summary = try #require(output.report["codesign"])
        #expect(summary.contains("Identifier=com.example.storefront.demo"))
        #expect(summary.contains("TeamIdentifier=TESTTEAM01"))
        #expect(summary.contains("Authority=\(setup.identity.identity.sha1)"))
        #expect(output.report["profile_uuid"] == setup.job.profile.uuid)
    }

    @Test func rejectsAModifiedExecutable() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let output = try Signer().sign(job: setup.job, identity: setup.identity, source: setup.source, workDirectory: setup.work)

        let app = try Signer.unpack(output.ipa, into: setup.work.appendingPathComponent("tampered"), code: "UNPACK_FAILED")
        let executable = app.appendingPathComponent("DemoApp")
        var bytes = try Data(contentsOf: executable)
        bytes[4096] ^= 0xFF
        try bytes.write(to: executable)

        #expect {
            try Signer.verify(app: app, job: setup.job, profile: setup.profile, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, message, _) = error else { return false }
            return code == "CODESIGN_VERIFY_FAILED" && message.contains("code page 1 was modified")
        }
    }

    @Test func refusesASourceThatIsNotTheLeasedOne() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        var job = setup.job
        job.source.sha256 = String(repeating: "0", count: 64)

        #expect {
            try Signer().sign(job: job, identity: setup.identity, source: setup.source, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, _, _) = error else { return false }
            return code == "SOURCE_HASH_MISMATCH"
        }
    }

    @Test func refusesToSignWithAnotherCertificate() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        var job = setup.job
        job.certificateSHA1 = String(repeating: "A", count: 40)

        #expect {
            try Signer().sign(job: job, identity: setup.identity, source: setup.source, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, _, _) = error else { return false }
            return code == "CERTIFICATE_NOT_HELD"
        }
    }
}

// MARK: - Fixtures

enum Tools {
    static func available(_ name: String) -> Bool { (try? Shell.locate(name)) != nil }

    @discardableResult
    static func openssl(_ arguments: [String]) throws -> Shell.Result {
        try Shell.require("TEST_SETUP", "openssl", arguments)
    }
}

/// A throwaway signing identity: a CA with Apple WWDR G3's name (zsign looks the
/// issuer up by name and embeds its own copy of the intermediate) and a leaf
/// "Apple Distribution" certificate for team TESTTEAM01, stored the way the
/// runner reads identities (`test.key` + `test.cer` in DER, as Apple issues it).
struct TestIdentity {
    var root: URL
    var identities: URL
    var certificatePEM: URL
    var privateKey: URL

    static func make() throws -> TestIdentity {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent("runner-test-\(UUID().uuidString)", isDirectory: true)
        let identities = root.appendingPathComponent("identities", isDirectory: true)
        try FileManager.default.createDirectory(at: identities, withIntermediateDirectories: true)
        let file = { (name: String) in root.appendingPathComponent(name).path }

        try Tools.openssl(["req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-keyout", file("ca.key"), "-out", file("ca.pem"),
                           "-subj", "/CN=Apple Worldwide Developer Relations Certification Authority/OU=G3/O=Apple Inc./C=US",
                           "-addext", "basicConstraints=critical,CA:true"])
        let key = identities.appendingPathComponent("test.key")
        try Tools.openssl(["req", "-newkey", "rsa:2048", "-nodes", "-keyout", key.path, "-out", file("test.csr"),
                           "-subj", "/UID=TESTTEAM01/CN=Apple Distribution: Runner Test (TESTTEAM01)/OU=TESTTEAM01/O=Runner Test/C=US"])
        try "keyUsage=critical,digitalSignature\nextendedKeyUsage=codeSigning\nbasicConstraints=critical,CA:false\n".write(toFile: file("leaf.ext"), atomically: true, encoding: .utf8)
        try Tools.openssl(["x509", "-req", "-in", file("test.csr"), "-CA", file("ca.pem"), "-CAkey", file("ca.key"), "-CAcreateserial",
                           "-days", "2", "-extfile", file("leaf.ext"), "-out", file("test.pem")])
        try Tools.openssl(["x509", "-in", file("test.pem"), "-outform", "DER", "-out", identities.appendingPathComponent("test.cer").path])

        return TestIdentity(root: root, identities: identities, certificatePEM: root.appendingPathComponent("test.pem"), privateKey: key)
    }
}

/// The identity, an ad hoc profile signed by it, the fixture IPA and a lease for them.
struct SigningSetup {
    var folder: TestIdentity
    var identity: IdentityFiles
    var profile: Data
    var source: URL
    var work: URL
    var job: SigningJob

    init() throws {
        folder = try TestIdentity.make()
        identity = try #require(try Identities.load(directory: folder.identities).first)

        let certificate = try Data(contentsOf: identity.certificate)
        let plist = folder.root.appendingPathComponent("profile.plist")
        try PropertyListSerialization.data(fromPropertyList: [
            "Name": "Runner Test AdHoc",
            "UUID": "11111111-2222-3333-4444-555555555555",
            "TeamIdentifier": ["TESTTEAM01"],
            "DeveloperCertificates": [certificate],
            "ProvisionedDevices": ["00008140-000000000000001C"],
            "ExpirationDate": Date().addingTimeInterval(86_400),
            "Entitlements": ["application-identifier": "TESTTEAM01.com.example.storefront.demo", "get-task-allow": false, "keychain-access-groups": ["TESTTEAM01.*"]],
        ], format: .xml, options: 0).write(to: plist)
        let profileFile = folder.root.appendingPathComponent("test.mobileprovision")
        try Tools.openssl(["cms", "-sign", "-in", plist.path, "-signer", folder.certificatePEM.path, "-inkey", folder.privateKey.path,
                           "-outform", "DER", "-nodetach", "-binary", "-out", profileFile.path])
        profile = try Data(contentsOf: profileFile)

        source = try #require(Bundle.module.url(forResource: "DemoApp", withExtension: "ipa", subdirectory: "Fixtures"))
        work = folder.root.appendingPathComponent("job", isDirectory: true)
        try FileManager.default.createDirectory(at: work, withIntermediateDirectories: true)

        job = SigningJob(
            jobID: "job_test", signedBuildID: "sb_test", bundleIdentifier: "com.example.storefront.demo", teamIdentifier: "TESTTEAM01",
            certificateSHA1: identity.identity.sha1,
            profile: .init(uuid: "11111111-2222-3333-4444-555555555555", content: profile.base64EncodedString()),
            source: .init(sha256: try RequestSigner.sha256(fileAt: source), sizeBytes: 0, path: "/source"),
            uploadPath: "/upload", resultPath: "/result"
        )
    }

    func remove() { try? FileManager.default.removeItem(at: folder.root) }
}
