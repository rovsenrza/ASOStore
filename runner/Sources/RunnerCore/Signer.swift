import Foundation

/// Re-signs one IPA for one device profile (IMPLEMENTATION_PLAN §5.6, P6-RUN-01).
///
/// Steps: check the source hash → unpack → embed the profile → derive
/// entitlements from the profile → sign frameworks and dylibs, then the app
/// (inside-out) → `codesign --verify --strict` → repack. Everything happens in
/// a private job folder that is deleted afterwards (P6-RUN-02).
public struct Signer: Sendable {
    public let keychain: String?

    public init(keychain: String?) {
        self.keychain = keychain
    }

    public struct Output: Sendable {
        public var ipa: URL
        public var sha256: String
        public var report: [String: String]
    }

    public func sign(job: SigningJob, source: URL, workDirectory: URL) throws -> Output {
        let started = Date()

        // P6-RUN-02: never sign something other than what the lease describes.
        let sourceHash = try RequestSigner.sha256(fileAt: source)
        guard sourceHash == job.source.sha256.lowercased() else {
            throw RunnerError.job(code: "SOURCE_HASH_MISMATCH", message: "Downloaded \(sourceHash), lease says \(job.source.sha256)", retryable: true)
        }

        let unpacked = workDirectory.appendingPathComponent("unpacked", isDirectory: true)
        try Shell.require("UNPACK_FAILED", "/usr/bin/ditto", ["-x", "-k", source.path, unpacked.path])

        let payload = unpacked.appendingPathComponent("Payload", isDirectory: true)
        let apps = try FileManager.default.contentsOfDirectory(at: payload, includingPropertiesForKeys: nil).filter { $0.pathExtension == "app" }
        guard apps.count == 1, let app = apps.first else {
            throw RunnerError.job(code: "NO_APP_BUNDLE", message: "Expected exactly one Payload/*.app", retryable: false)
        }

        // Extensions need their own profiles (one per bundle ID); not provisioned yet.
        for folder in ["PlugIns", "Extensions", "Watch", "AppClips"] where FileManager.default.fileExists(atPath: app.appendingPathComponent(folder).path) {
            throw RunnerError.job(code: "NESTED_PROFILE_REQUIRED", message: "\(folder)/ bundles need their own profiles", retryable: false)
        }

        guard let profileData = Data(base64Encoded: job.profile.content) else {
            throw RunnerError.job(code: "PROFILE_INVALID", message: "Profile content is not base64", retryable: false)
        }
        let profileFile = app.appendingPathComponent("embedded.mobileprovision")
        try profileData.write(to: profileFile)

        let entitlements = try Self.entitlements(fromProfileAt: profileFile, bundleIdentifier: job.bundleIdentifier, teamIdentifier: job.teamIdentifier)
        let entitlementsFile = workDirectory.appendingPathComponent("entitlements.plist")
        try PropertyListSerialization.data(fromPropertyList: entitlements, format: .xml, options: 0).write(to: entitlementsFile)

        // Inside-out: nested code first, the app last.
        for nested in try Self.nestedCode(in: app) {
            try codesign(["--force", "--sign", job.certificateSHA1, "--timestamp=none", "--generate-entitlement-der", nested.path])
        }
        try codesign(["--force", "--sign", job.certificateSHA1, "--timestamp=none", "--generate-entitlement-der", "--entitlements", entitlementsFile.path, app.path])

        let verify = try Shell.run("/usr/bin/codesign", ["--verify", "--strict", "--deep", "--verbose=2", app.path])
        guard verify.status == 0 else {
            throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: verify.error, retryable: false)
        }
        let details = try Shell.run("/usr/bin/codesign", ["-dvvv", app.path]).error

        let output = workDirectory.appendingPathComponent("signed.ipa")
        try Shell.require("REPACK_FAILED", "/usr/bin/ditto", ["-c", "-k", "--sequesterRsrc", "--keepParent", payload.path, output.path])

        return Output(ipa: output, sha256: try RequestSigner.sha256(fileAt: output), report: [
            "codesign": Self.summary(details),
            "duration_seconds": String(format: "%.1f", Date().timeIntervalSince(started)),
            "profile_uuid": job.profile.uuid,
        ])
    }

    private func codesign(_ arguments: [String]) throws {
        var arguments = arguments
        if let keychain { arguments.insert(contentsOf: ["--keychain", keychain], at: 0) }
        try Shell.require("CODESIGN_FAILED", "/usr/bin/codesign", arguments)
    }

    /// The profile's entitlements, narrowed to this app: application-identifier
    /// and team ID are fixed to the leased bundle and team.
    public static func entitlements(fromProfileAt url: URL, bundleIdentifier: String, teamIdentifier: String) throws -> [String: Any] {
        let decoded = try Shell.require("PROFILE_INVALID", "/usr/bin/security", ["cms", "-D", "-i", url.path]).output
        return try entitlements(fromProfilePlist: Data(decoded.utf8), bundleIdentifier: bundleIdentifier, teamIdentifier: teamIdentifier)
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

    /// Frameworks and dylibs directly under Frameworks/, deepest first.
    static func nestedCode(in app: URL) throws -> [URL] {
        let frameworks = app.appendingPathComponent("Frameworks", isDirectory: true)
        guard FileManager.default.fileExists(atPath: frameworks.path) else { return [] }
        return try FileManager.default.contentsOfDirectory(at: frameworks, includingPropertiesForKeys: nil)
            .filter { ["framework", "dylib"].contains($0.pathExtension) }
            .sorted { $0.path > $1.path }
    }

    /// Identifier, TeamIdentifier and Authority lines of `codesign -dvvv`.
    static func summary(_ details: String) -> String {
        details.split(separator: "\n")
            .filter { $0.hasPrefix("Identifier=") || $0.hasPrefix("TeamIdentifier=") || $0.hasPrefix("Authority=") || $0.hasPrefix("CDHash=") }
            .joined(separator: "; ")
    }
}
