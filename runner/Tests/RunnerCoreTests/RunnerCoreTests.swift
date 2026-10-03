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

@Suite struct TransferConfigTests {
    @Test func rejectsUnboundedWorkAndCompressionSettings() throws {
        let env = ["STOREFRONT_RUNNER_BASE_URL": "https://backend.test", "STOREFRONT_RUNNER_KEY_ID": "rk", "STOREFRONT_RUNNER_SECRET": "secret", "STOREFRONT_RUNNER_IDENTITIES_DIR": "/keys"]
        for (key, value) in [("STOREFRONT_RUNNER_CONCURRENCY", "0"), ("STOREFRONT_RUNNER_CONCURRENCY", "5"), ("STOREFRONT_RUNNER_ZIP_LEVEL", "10"), ("STOREFRONT_RUNNER_ZIP_LEVEL", "bad")] {
            #expect(throws: RunnerError.self) { try RunnerConfig.fromEnvironment(env.merging([key: value]) { $1 }) }
        }
        let config = try RunnerConfig.fromEnvironment(env)
        #expect(config.concurrency == 2)
        #expect(config.zipLevel == 1)
        let client = WorkerClient(config: config)
        let source = SigningJob.Source(sha256: "hash", sizeBytes: 10, path: "/source", downloadURL: URL(string: "https://objects.test/file?signature=short-lived"))
        let request = try client.sourceRequest(source)
        #expect(request.url == source.downloadURL)
        #expect(request.allHTTPHeaderFields?.isEmpty != false)
        var unsafe = source
        unsafe.downloadURL = URL(string: "http://objects.test/file")
        #expect(throws: RunnerError.self) { try client.sourceRequest(unsafe) }
        let old = try JSONDecoder().decode(SigningJob.Source.self, from: Data(#"{"sha256":"hash","size_bytes":10,"path":"/source"}"#.utf8))
        #expect(old.downloadURL == nil)
    }
}

/// End to end on Linux (and on a Mac with zsign installed): sign the unsigned
/// DemoApp fixture with a throwaway identity, then check the result the way the
/// runner does before uploading.
@Suite(.enabled(if: Tools.available("zsign") && Tools.available("openssl") && Tools.available("unzip")))
struct SigningTests {
    @Test func rebuildsACorruptedSigningTreeFromTheOriginalSource() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let settings = RunnerCache.Settings(directory: setup.folder.root.appendingPathComponent("cache"))
        let signer = Signer(cache: settings)
        _ = try signer.sign(job: setup.job, identity: setup.identity, source: setup.source, workDirectory: setup.work)
        let entries = try FileManager.default.contentsOfDirectory(at: settings.directory.appendingPathComponent("entries"), includingPropertiesForKeys: nil)
        let entry = try #require(entries.first { $0.lastPathComponent.hasPrefix("sign-") })
        // An extra resource is not present in the leased source and cannot be incorporated.
        let payload = entry.appendingPathComponent("unpacked/Payload")
        let app = try #require(FileManager.default.contentsOfDirectory(at: payload, includingPropertiesForKeys: nil).first)
        try Data("untrusted resource".utf8).write(to: app.appendingPathComponent("unexpected.txt"))
        let work = setup.folder.root.appendingPathComponent("rebuilt-job")
        try FileManager.default.createDirectory(at: work, withIntermediateDirectories: true)
        let output = try signer.sign(job: setup.job, identity: setup.identity, source: setup.source, workDirectory: work)
        #expect(output.report["workspace_cache"] == "miss")
        let checked = try Signer.unpack(output.ipa, into: work.appendingPathComponent("inspect"), code: "TEST_SETUP")
        #expect(!FileManager.default.fileExists(atPath: checked.appendingPathComponent("unexpected.txt").path))
    }

    @Test func reusesASigningTreeWithoutReusingZsignHashesWithANewDeviceProfile() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let settings = RunnerCache.Settings(directory: setup.folder.root.appendingPathComponent("cache"))
        let signer = Signer(cache: settings)
        let first = try signer.sign(job: setup.job, identity: setup.identity, source: setup.source, workDirectory: setup.work)
        #expect(first.report["workspace_cache"] == "miss")

        var job = setup.job
        let uuid = "44444444-2222-3333-4444-555555555555"
        let profile = try SigningSetup.makeProfile(folder: setup.folder, identity: setup.identity, bundleIdentifier: job.bundleIdentifier, uuid: uuid)
        job.profile = .init(uuid: uuid, content: profile.base64EncodedString())
        let secondWork = setup.folder.root.appendingPathComponent("job-second")
        try FileManager.default.createDirectory(at: secondWork, withIntermediateDirectories: true)
        let second = try signer.sign(job: job, identity: setup.identity, source: setup.source, workDirectory: secondWork)
        #expect(second.report["workspace_cache"] == "hit")
        #expect(second.report["zsign_cache"] == "miss")
        #expect(second.report["profile_uuid"] == uuid)
        #expect(second.sha256 != first.sha256)
        let app = try Signer.unpack(second.ipa, into: secondWork.appendingPathComponent("inspect"), code: "TEST_SETUP")
        let seal = try #require(try PropertyListSerialization.propertyList(from: Data(contentsOf: app.appendingPathComponent("_CodeSignature/CodeResources")), format: nil) as? [String: Any])
        #expect((seal["files2"] as? [String: Any])?["DemoApp"] == nil)
        #if os(macOS)
        try Shell.require("TEST_SETUP", "codesign", ["--verify", "--deep", "--strict", app.path])
        #endif
    }

    @Test func cachedExtensionsEmbedEachNewProfile() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let (source, original, _) = try setup.withExtension()
        let signer = Signer(cache: .init(directory: setup.folder.root.appendingPathComponent("cache")))
        _ = try signer.sign(job: original, identity: setup.identity, source: source, workDirectory: setup.work)
        var job = original
        let uuid = "44444444-2222-3333-4444-555555555555"
        let appProfile = try SigningSetup.makeProfile(folder: setup.folder, identity: setup.identity, bundleIdentifier: job.bundleIdentifier, uuid: uuid)
        let nestedProfile = try SigningSetup.makeProfile(folder: setup.folder, identity: setup.identity, bundleIdentifier: job.nested[0].bundleIdentifier, uuid: "55555555-2222-3333-4444-555555555555")
        job.profile = .init(uuid: uuid, content: appProfile.base64EncodedString())
        job.nested[0].profile = .init(uuid: "55555555-2222-3333-4444-555555555555", content: nestedProfile.base64EncodedString())
        let work = setup.folder.root.appendingPathComponent("second-job")
        try FileManager.default.createDirectory(at: work, withIntermediateDirectories: true)
        let output = try signer.sign(job: job, identity: setup.identity, source: source, workDirectory: work)
        #expect(output.report["workspace_cache"] == "hit")
        #expect(output.report["zsign_cache"] == "miss")
        #expect(output.report["codesign"]?.contains("Extensions=1") == true)
    }

    @Test(.enabled(if: Tools.available("zip")))
    func rejectsAModifiedSealedResource() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let unpacked = setup.folder.root.appendingPathComponent("resources")
        let app = try Signer.unpack(setup.source, into: unpacked, code: "TEST_SETUP")
        try Data("original resource".utf8).write(to: app.appendingPathComponent("resource.txt"))
        let source = setup.folder.root.appendingPathComponent("resources.ipa")
        try Shell.require("TEST_SETUP", "zip", ["-qry", source.path, "Payload"], in: unpacked)
        var job = setup.job
        job.source.sha256 = try RequestSigner.sha256(fileAt: source)
        let output = try Signer().sign(job: job, identity: setup.identity, source: source, workDirectory: setup.work)
        let checked = try Signer.unpack(output.ipa, into: setup.work.appendingPathComponent("tampered"), code: "TEST_SETUP")
        try Data("modified resource".utf8).write(to: checked.appendingPathComponent("resource.txt"))
        #expect {
            try Signer.verify(app: checked, job: job, profile: setup.profile, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, message, _) = error else { return false }
            return code == "CODESIGN_VERIFY_FAILED" && message.contains("resource.txt")
        }
    }

    @Test(.enabled(if: Tools.available("zip")))
    func compressesResourcesAndKeepsValidSignatures() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let unpacked = setup.folder.root.appendingPathComponent("compressible")
        let app = try Signer.unpack(setup.source, into: unpacked, code: "TEST_SETUP")
        try Data(repeating: 65, count: 1024 * 1024).write(to: app.appendingPathComponent("resource.txt"))
        let source = setup.folder.root.appendingPathComponent("compressible.ipa")
        try Shell.require("TEST_SETUP", "sh", ["-c", "cd '\(unpacked.path)' && zip -qry '\(source.path)' Payload"])
        var job = setup.job
        job.source.sha256 = try RequestSigner.sha256(fileAt: source)
        let output = try Signer().sign(job: job, identity: setup.identity, source: source, workDirectory: setup.work)
        let size = try #require(FileManager.default.attributesOfItem(atPath: output.ipa.path)[.size] as? NSNumber)
        #expect(size.intValue < 200_000)
        #expect(output.report["zip_level"] == "1")
        #expect(output.report["codesign"]?.contains("TeamIdentifier=TESTTEAM01") == true)
    }

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

    @Test(.enabled(if: Tools.available("zip")))
    func signsAnExtensionWithItsOwnProfileAndReidentifiesBoth() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let (source, job, extensionProfile) = try setup.withExtension()

        let output = try Signer().sign(job: job, identity: setup.identity, source: source, workDirectory: setup.work)

        let summary = try #require(output.report["codesign"])
        #expect(summary.contains("Identifier=com.ruappstore.test;"))
        #expect(summary.contains("Extensions=1"))
        let app = try Signer.unpack(output.ipa, into: setup.work.appendingPathComponent("result"), code: "UNPACK_FAILED")
        let appex = app.appendingPathComponent("PlugIns/Tunnel.appex")
        #expect(try Data(contentsOf: appex.appendingPathComponent("embedded.mobileprovision")) == extensionProfile)
        let info = try #require(try PropertyListSerialization.propertyList(from: Data(contentsOf: appex.appendingPathComponent("Info.plist")), format: nil) as? [String: Any])
        let executable = try #require(info["CFBundleExecutable"] as? String)
        let extensionSignature = try #require(try CodeSignature.read(fileAt: appex.appendingPathComponent(executable)).first)
        #expect(extensionSignature.identifier == "com.ruappstore.test.tunnel")
        let entitlements = try #require(try extensionSignature.entitlements.flatMap { try PropertyListSerialization.propertyList(from: $0, format: nil) as? [String: Any] })
        #expect(entitlements["application-identifier"] as? String == "TESTTEAM01.com.ruappstore.test.tunnel")
    }

    @Test(.enabled(if: Tools.available("zip")))
    func refusesAnExtensionTheLeaseHasNoProfileFor() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        var (source, job, _) = try setup.withExtension()
        job.nested = []

        #expect {
            try Signer().sign(job: job, identity: setup.identity, source: source, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, message, _) = error else { return false }
            return code == "NESTED_PROFILE_REQUIRED" && message.contains("PlugIns/Tunnel.appex")
        }
    }

    @Test func allowsResourceBundlesButRejectsExecutableBundlesWithoutProfiles() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let app = try Signer.unpack(setup.source, into: setup.work.appendingPathComponent("resources"), code: "TEST_SETUP")
        let plugins = app.appendingPathComponent("PlugIns", isDirectory: true)
        let resource = plugins.appendingPathComponent("Images.bundle", isDirectory: true)
        try FileManager.default.createDirectory(at: resource, withIntermediateDirectories: true)
        let info: [String: Any] = ["CFBundlePackageType": "BNDL", "CFBundleIdentifier": "com.example.images"]
        try PropertyListSerialization.data(fromPropertyList: info, format: .xml, options: 0)
            .write(to: resource.appendingPathComponent("Info.plist"))

        try Signer.checkNested(app: app, leased: [])

        var executableInfo = info
        executableInfo["CFBundleExecutable"] = "Images"
        try PropertyListSerialization.data(fromPropertyList: executableInfo, format: .xml, options: 0)
            .write(to: resource.appendingPathComponent("Info.plist"))
        #expect {
            try Signer.checkNested(app: app, leased: [])
        } throws: { error in
            guard case let RunnerError.job(code, message, _) = error else { return false }
            return code == "NESTED_PROFILE_REQUIRED" && message.contains("PlugIns/Images.bundle")
        }
    }

    @Test(.enabled(if: Tools.available("zip")))
    func refusesAnExtensionProfileForAnotherBundle() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        var (source, job, _) = try setup.withExtension()
        job.nested[0].bundleIdentifier = "com.ruappstore.test.other"

        #expect {
            try Signer().sign(job: job, identity: setup.identity, source: source, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, _, _) = error else { return false }
            return code == "PROFILE_MISMATCH"
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

/// Swift runtime dylibs that older apps embed carry their Info.plist inside the binary
/// (`__TEXT,__info_plist`); codesign hashes it into special slot 1 with no bundle around.
@Suite(.enabled(if: Tools.available("codesign") && Tools.available("clang")))
struct EmbeddedInfoPlistTests {
    private func signedDylib(plist: String) throws -> (URL, URL) {
        let work = FileManager.default.temporaryDirectory.appendingPathComponent("embedded-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: work, withIntermediateDirectories: true)
        let source = work.appendingPathComponent("lib.c"), info = work.appendingPathComponent("Info.plist"), dylib = work.appendingPathComponent("libsample.dylib")
        try "int sample(void) { return 42; }".write(to: source, atomically: true, encoding: .utf8)
        try plist.write(to: info, atomically: true, encoding: .utf8)
        try Shell.require("TEST_SETUP", "clang", ["-dynamiclib", source.path, "-o", dylib.path, "-Wl,-sectcreate,__TEXT,__info_plist,\(info.path)"])
        try Shell.require("TEST_SETUP", "codesign", ["-f", "-s", "-", "-i", "com.example.runtime.sample", dylib.path])
        return (work, dylib)
    }

    private let plist = """
    <?xml version="1.0" encoding="UTF-8"?>
    <plist version="1.0"><dict><key>CFBundleIdentifier</key><string>com.example.runtime.sample</string></dict></plist>
    """

    @Test func verifiesTheInfoPlistSlotOfABareDylib() throws {
        let (work, dylib) = try signedDylib(plist: plist)
        defer { try? FileManager.default.removeItem(at: work) }

        let signature = try #require(try CodeSignature.read(fileAt: dylib).first)
        #expect(signature.specialHashes[1]?.contains { $0 != 0 } == true)
        try signature.verifyHashes(bundle: nil)
    }

    @Test func rejectsAChangedEmbeddedInfoPlist() throws {
        let (work, dylib) = try signedDylib(plist: plist)
        defer { try? FileManager.default.removeItem(at: work) }

        var bytes = try Data(contentsOf: dylib)
        let marker = Data("com.example.runtime.sample</string>".utf8)
        let range = try #require(bytes.range(of: marker))
        bytes[range.lowerBound] = UInt8(ascii: "C")
        try bytes.write(to: dylib)

        let signature = try #require(try CodeSignature.read(fileAt: dylib).first)
        #expect(throws: (any Error).self) { try signature.verifyHashes(bundle: nil) }
    }
}

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

        profile = try Self.makeProfile(folder: folder, identity: identity, bundleIdentifier: "com.example.storefront.demo", uuid: "11111111-2222-3333-4444-555555555555")

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

    /// An ad hoc profile for one bundle ID, signed by the test identity.
    static func makeProfile(folder: TestIdentity, identity: IdentityFiles, bundleIdentifier: String, uuid: String) throws -> Data {
        let certificate = try Data(contentsOf: identity.certificate)
        let plist = folder.root.appendingPathComponent("\(uuid).plist")
        try PropertyListSerialization.data(fromPropertyList: [
            "Name": "Runner Test AdHoc \(bundleIdentifier)",
            "UUID": uuid,
            "TeamIdentifier": ["TESTTEAM01"],
            "DeveloperCertificates": [certificate],
            "ProvisionedDevices": ["00008140-000000000000001C"],
            "ExpirationDate": Date().addingTimeInterval(86_400),
            "Entitlements": [
                "application-identifier": "TESTTEAM01.\(bundleIdentifier)", "com.apple.developer.team-identifier": "TESTTEAM01",
                "get-task-allow": false, "keychain-access-groups": ["TESTTEAM01.*"],
            ],
        ], format: .xml, options: 0).write(to: plist)
        let profileFile = folder.root.appendingPathComponent("\(uuid).mobileprovision")
        try Tools.openssl(["cms", "-sign", "-in", plist.path, "-signer", folder.certificatePEM.path, "-inkey", folder.privateKey.path,
                           "-outform", "DER", "-nodetach", "-binary", "-out", profileFile.path])
        return try Data(contentsOf: profileFile)
    }

    /// The fixture with an app extension added (a copy of the app as PlugIns/Tunnel.appex,
    /// bundle ID com.example.storefront.demo.tunnel), and a lease that re-identifies both
    /// to com.ruappstore.test and com.ruappstore.test.tunnel.
    func withExtension() throws -> (source: URL, job: SigningJob, extensionProfile: Data) {
        let build = folder.root.appendingPathComponent("with-extension", isDirectory: true)
        let app = try Signer.unpack(source, into: build, code: "TEST_SETUP")
        let appex = app.appendingPathComponent("PlugIns/Tunnel.appex", isDirectory: true)
        try FileManager.default.createDirectory(at: appex, withIntermediateDirectories: true)
        var info = try #require(try PropertyListSerialization.propertyList(from: Data(contentsOf: app.appendingPathComponent("Info.plist")), format: nil) as? [String: Any])
        let executable = try #require(info["CFBundleExecutable"] as? String)
        try FileManager.default.copyItem(at: app.appendingPathComponent(executable), to: appex.appendingPathComponent(executable))
        info["CFBundleIdentifier"] = "com.example.storefront.demo.tunnel"
        info["CFBundlePackageType"] = "XPC!"
        info["NSExtension"] = ["NSExtensionPointIdentifier": "com.apple.networkextension.packet-tunnel", "NSExtensionPrincipalClass": "PacketTunnelProvider"]
        try PropertyListSerialization.data(fromPropertyList: info, format: .xml, options: 0).write(to: appex.appendingPathComponent("Info.plist"))

        let ipa = folder.root.appendingPathComponent("with-extension.ipa")
        try Shell.require("TEST_SETUP", "sh", ["-c", "cd '\(build.path)' && zip -qry '\(ipa.path)' Payload"])

        let appProfile = try Self.makeProfile(folder: folder, identity: identity, bundleIdentifier: "com.ruappstore.test", uuid: "22222222-2222-3333-4444-555555555555")
        let extensionProfile = try Self.makeProfile(folder: folder, identity: identity, bundleIdentifier: "com.ruappstore.test.tunnel", uuid: "33333333-2222-3333-4444-555555555555")
        var job = self.job
        job.bundleIdentifier = "com.ruappstore.test"
        job.profile = .init(uuid: "22222222-2222-3333-4444-555555555555", content: appProfile.base64EncodedString())
        job.nested = [.init(path: "PlugIns/Tunnel.appex", bundleIdentifier: "com.ruappstore.test.tunnel",
                            profile: .init(uuid: "33333333-2222-3333-4444-555555555555", content: extensionProfile.base64EncodedString()))]
        job.source = .init(sha256: try RequestSigner.sha256(fileAt: ipa), sizeBytes: 0, path: "/source")
        return (ipa, job, extensionProfile)
    }
}

@Suite struct BootstrapClaimEmbedTests {
    private func bundle(_ plist: String) throws -> URL {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("embed-\(UUID().uuidString)", isDirectory: true)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        try plist.write(to: dir.appendingPathComponent("Info.plist"), atomically: true, encoding: .utf8)
        return dir
    }

    @Test func embedsTheClaimKeepingOtherKeys() throws {
        let dir = try bundle("""
        <?xml version="1.0" encoding="UTF-8"?>
        <plist version="1.0"><dict><key>CFBundleIdentifier</key><string>com.ruappstore.app</string><key>CFBundleExecutable</key><string>Storefront</string></dict></plist>
        """)
        defer { try? FileManager.default.removeItem(at: dir) }

        try Signer.setInfoValue("one-time-code-abc", forKey: "StorefrontBootstrapClaim", bundle: dir)

        let info = try PropertyListSerialization.propertyList(from: Data(contentsOf: dir.appendingPathComponent("Info.plist")), format: nil) as? [String: Any]
        #expect(info?["StorefrontBootstrapClaim"] as? String == "one-time-code-abc")
        #expect(info?["CFBundleIdentifier"] as? String == "com.ruappstore.app")
        #expect(info?["CFBundleExecutable"] as? String == "Storefront")
    }

    @Test func rejectsANonLetterKeyAndOversizeValue() throws {
        let dir = try bundle("<?xml version=\"1.0\"?><plist version=\"1.0\"><dict/></plist>")
        defer { try? FileManager.default.removeItem(at: dir) }
        #expect(throws: (any Error).self) { try Signer.setInfoValue("x", forKey: "Bad Key", bundle: dir) }
        #expect(throws: (any Error).self) { try Signer.setInfoValue(String(repeating: "a", count: 5000), forKey: "Claim", bundle: dir) }
    }

    @Test func decodesTheClaimFromTheLeaseJSON() throws {
        // The backend sends the lease as JSON with snake_case keys; a lease without the field
        // (any other app) decodes to nil.
        let json = """
        {"job_id":"j","signed_build_id":"b","bundle_identifier":"com.ruappstore.app","team_identifier":"TEAM123456",
         "certificate_sha1":"AAAA","profile":{"uuid":"U","content":""},"bootstrap_claim":"one-time-code",
         "source":{"sha256":"0","size_bytes":1,"path":"x"},"upload_path":"u","result_path":"r"}
        """
        let job = try JSONDecoder().decode(SigningJob.self, from: Data(json.utf8))
        #expect(job.bootstrapClaim == "one-time-code")

        let plain = try JSONDecoder().decode(SigningJob.self, from: Data(json.replacingOccurrences(of: "\"bootstrap_claim\":\"one-time-code\",", with: "").utf8))
        #expect(plain.bootstrapClaim == nil)
    }
}
