import Foundation

/// Re-signs one IPA for one device profile with zsign (IMPLEMENTATION_PLAN §5.6, P6-RUN-01).
///
/// Steps: check the source hash → unpack → refuse nested bundles → derive
/// entitlements from the profile → zsign (embeds the profile, signs
/// Frameworks/ then the app, repacks) → unpack the result and check its
/// signatures (CodeSignature). Everything happens in a private job folder
/// that is deleted afterwards (P6-RUN-02).
public struct Signer: Sendable {
    /// zsign executable, a path or a name looked up in PATH.
    public let zsign: String

    public init(zsign: String = "zsign") {
        self.zsign = zsign
    }

    public struct Output: Sendable {
        public var ipa: URL
        public var sha256: String
        public var report: [String: String]
    }

    public func sign(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> Output {
        let started = Date()

        // P6-RUN-02: never sign something other than what the lease describes.
        let sourceHash = try RequestSigner.sha256(fileAt: source)
        guard sourceHash == job.source.sha256.lowercased() else {
            throw RunnerError.job(code: "SOURCE_HASH_MISMATCH", message: "Downloaded \(sourceHash), lease says \(job.source.sha256)", retryable: true)
        }
        guard identity.identity.sha1 == job.certificateSHA1.uppercased() else {
            throw RunnerError.job(code: "CERTIFICATE_NOT_HELD", message: "Lease wants \(job.certificateSHA1)", retryable: true)
        }

        let unpacked = workDirectory.appendingPathComponent("unpacked", isDirectory: true)
        let app = try Self.unpack(source, into: unpacked, code: "UNPACK_FAILED")

        // Extensions need their own profiles (one per bundle ID); not provisioned yet.
        for folder in ["PlugIns", "Extensions", "Watch", "AppClips"] where FileManager.default.fileExists(atPath: app.appendingPathComponent(folder).path) {
            throw RunnerError.job(code: "NESTED_PROFILE_REQUIRED", message: "\(folder)/ bundles need their own profiles", retryable: false)
        }

        guard let profileData = Data(base64Encoded: job.profile.content) else {
            throw RunnerError.job(code: "PROFILE_INVALID", message: "Profile content is not base64", retryable: false)
        }
        let profileFile = workDirectory.appendingPathComponent("profile.mobileprovision")
        try profileData.write(to: profileFile)

        let entitlements = try Self.entitlements(fromProfile: profileData, bundleIdentifier: job.bundleIdentifier, teamIdentifier: job.teamIdentifier)
        let entitlementsFile = workDirectory.appendingPathComponent("entitlements.plist")
        try PropertyListSerialization.data(fromPropertyList: entitlements, format: .xml, options: 0).write(to: entitlementsFile)

        // -f: no signing cache, so every job signs every file.
        let output = workDirectory.appendingPathComponent("signed.ipa")
        let zsigned = try Shell.run(zsign, [
            "-f", "-k", identity.privateKey.path, "-c", identity.certificate.path,
            "-m", profileFile.path, "-e", entitlementsFile.path, "-o", output.path, app.path,
        ])
        guard zsigned.status == 0, FileManager.default.fileExists(atPath: output.path) else {
            throw RunnerError.job(code: "CODESIGN_FAILED", message: Self.zsignError(zsigned.output + zsigned.error), retryable: false)
        }

        // Check what will be uploaded, not the folder zsign worked in.
        let checked = try Self.unpack(output, into: workDirectory.appendingPathComponent("check", isDirectory: true), code: "REPACK_FAILED")
        let signature = try Self.verify(app: checked, job: job, profile: profileData, workDirectory: workDirectory)

        return Output(ipa: output, sha256: try RequestSigner.sha256(fileAt: output), report: [
            "signer": "zsign",
            "codesign": signature,
            "duration_seconds": String(format: "%.1f", Date().timeIntervalSince(started)),
            "profile_uuid": job.profile.uuid,
        ])
    }

    /// Checks a signed app the way `codesign --verify --strict` did on macOS, plus
    /// what the lease requires: the profile is the leased one, and the app and
    /// every framework/dylib are signed by the leased certificate for the leased
    /// team, with the leased application identifier.
    static func verify(app: URL, job: SigningJob, profile: Data, workDirectory: URL) throws -> String {
        guard (try? Data(contentsOf: app.appendingPathComponent("embedded.mobileprovision"))) == profile else {
            throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "embedded.mobileprovision is not the leased profile", retryable: false)
        }
        let info = try PropertyListSerialization.propertyList(from: Data(contentsOf: app.appendingPathComponent("Info.plist")), format: nil) as? [String: Any]
        guard let executable = info?["CFBundleExecutable"] as? String else {
            throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "Info.plist has no CFBundleExecutable", retryable: false)
        }

        let certificate = job.certificateSHA1.uppercased()
        func check(_ binary: URL, bundle: URL?) throws -> CodeSignature {
            let slices = try CodeSignature.read(fileAt: binary)
            for slice in slices {
                try slice.verifyHashes(bundle: bundle)
                guard slice.teamIdentifier == job.teamIdentifier else {
                    throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "\(binary.lastPathComponent) team is \(slice.teamIdentifier ?? "not set"), lease says \(job.teamIdentifier)", retryable: false)
                }
                guard try slice.verifyCMS(workDirectory: workDirectory) == certificate else {
                    throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "\(binary.lastPathComponent) is not signed by \(certificate)", retryable: false)
                }
            }
            return slices[0]
        }

        let main = try check(app.appendingPathComponent(executable), bundle: app)
        guard main.identifier == job.bundleIdentifier else {
            throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "Signed identifier is \(main.identifier), lease says \(job.bundleIdentifier)", retryable: false)
        }
        let signedEntitlements = try main.entitlements.flatMap { try PropertyListSerialization.propertyList(from: $0, format: nil) as? [String: Any] }
        guard signedEntitlements?["application-identifier"] as? String == "\(job.teamIdentifier).\(job.bundleIdentifier)" else {
            throw RunnerError.job(code: "ENTITLEMENTS_MISMATCH", message: "Signed application-identifier is not \(job.teamIdentifier).\(job.bundleIdentifier)", retryable: false)
        }

        let nested = try nestedCode(in: app)
        for code in nested {
            if code.pathExtension == "framework" {
                let frameworkInfo = try PropertyListSerialization.propertyList(from: Data(contentsOf: code.appendingPathComponent("Info.plist")), format: nil) as? [String: Any]
                let name = frameworkInfo?["CFBundleExecutable"] as? String ?? code.deletingPathExtension().lastPathComponent
                _ = try check(code.appendingPathComponent(name), bundle: code)
            } else {
                _ = try check(code, bundle: nil)
            }
        }

        return "Identifier=\(main.identifier); TeamIdentifier=\(job.teamIdentifier); Authority=\(certificate); CDHash=\(main.cdHash); Nested=\(nested.count)"
    }

    /// Unzips an IPA and returns its only Payload/*.app.
    static func unpack(_ ipa: URL, into folder: URL, code: String) throws -> URL {
        try Shell.require(code, "unzip", ["-qq", ipa.path, "-d", folder.path])
        let payload = folder.appendingPathComponent("Payload", isDirectory: true)
        let apps = (try? FileManager.default.contentsOfDirectory(at: payload, includingPropertiesForKeys: nil).filter { $0.pathExtension == "app" }) ?? []
        guard apps.count == 1, let app = apps.first else {
            throw RunnerError.job(code: "NO_APP_BUNDLE", message: "Expected exactly one Payload/*.app", retryable: false)
        }
        return app
    }

    /// The profile's entitlements, narrowed to this app: application-identifier
    /// and team ID are fixed to the leased bundle and team. The profile is a CMS
    /// envelope around an XML plist; its Apple signature is checked by the device.
    public static func entitlements(fromProfile data: Data, bundleIdentifier: String, teamIdentifier: String) throws -> [String: Any] {
        try entitlements(fromProfilePlist: profilePlist(data), bundleIdentifier: bundleIdentifier, teamIdentifier: teamIdentifier)
    }

    /// The XML plist inside a .mobileprovision CMS envelope.
    public static func profilePlist(_ data: Data) throws -> Data {
        guard let start = data.range(of: Data("<?xml".utf8)), let end = data.range(of: Data("</plist>".utf8), in: start.lowerBound..<data.endIndex) else {
            throw RunnerError.job(code: "PROFILE_INVALID", message: "Profile has no plist", retryable: false)
        }
        return data.subdata(in: start.lowerBound..<end.upperBound)
    }

    public static func entitlements(fromProfilePlist data: Data, bundleIdentifier: String, teamIdentifier: String) throws -> [String: Any] {
        guard let profile = try PropertyListSerialization.propertyList(from: data, format: nil) as? [String: Any],
              var entitlements = profile["Entitlements"] as? [String: Any] else {
            throw RunnerError.job(code: "PROFILE_INVALID", message: "Profile has no Entitlements", retryable: false)
        }
        let teams = profile["TeamIdentifier"] as? [String] ?? []
        guard teams.contains(teamIdentifier) else {
            throw RunnerError.job(code: "TEAM_MISMATCH", message: "Profile belongs to \(teams), lease says \(teamIdentifier)", retryable: false)
        }

        entitlements["application-identifier"] = "\(teamIdentifier).\(bundleIdentifier)"
        entitlements["com.apple.developer.team-identifier"] = teamIdentifier
        return entitlements
    }

    /// Frameworks and dylibs directly under Frameworks/.
    static func nestedCode(in app: URL) throws -> [URL] {
        let frameworks = app.appendingPathComponent("Frameworks", isDirectory: true)
        guard FileManager.default.fileExists(atPath: frameworks.path) else { return [] }
        return try FileManager.default.contentsOfDirectory(at: frameworks, includingPropertiesForKeys: nil)
            .filter { ["framework", "dylib"].contains($0.pathExtension) }
            .sorted { $0.path > $1.path }
    }

    /// zsign's failure lines without colour codes, e.g. "Build CMS signature failed!".
    static func zsignError(_ output: String) -> String {
        let plain = output.replacingOccurrences(of: "\u{1B}\\[[0-9;]*m", with: "", options: .regularExpression)
        let lines = plain.split(separator: "\n").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
        let failures = lines.filter { $0.localizedCaseInsensitiveContains("fail") || $0.localizedCaseInsensitiveContains("error") || $0.localizedCaseInsensitiveContains("unknown") || $0.localizedCaseInsensitiveContains("can't") }
        return (failures.isEmpty ? Array(lines.suffix(3)) : failures).joined(separator: " | ")
    }
}
