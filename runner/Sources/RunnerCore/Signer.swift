import Foundation

/// Re-signs one IPA for one device profile with zsign (IMPLEMENTATION_PLAN §5.6, P6-RUN-01).
///
/// Steps: check the source hash → unpack → refuse bundles the lease has no
/// profile for → set the leased bundle IDs → zsign (embeds each profile, signs
/// Frameworks/, the extensions, then the app, repacks) → unpack the result and
/// check its signatures (CodeSignature). Without extensions the entitlements are
/// derived from the profile and passed with -e; with extensions each bundle takes
/// its own profile's entitlements. Everything happens in a private job folder
/// that is deleted afterwards (P6-RUN-02).
public struct Signer: Sendable {
    /// zsign executable, a path or a name looked up in PATH.
    public let zsign: String

    public let zipLevel: Int
    public let cache: RunnerCache.Settings?

    public init(zsign: String = "zsign", zipLevel: Int = 1, cache: RunnerCache.Settings? = nil) {
        self.zsign = zsign
        self.zipLevel = zipLevel
        self.cache = cache
    }

    public struct Output: Sendable {
        public var ipa: URL
        public var sha256: String
        public var report: [String: String]
    }

    public func sign(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> Output {
        guard (0...9).contains(zipLevel) else { throw RunnerError.configuration("ZIP level must be between 0 and 9") }
        let started = Date()

        // P6-RUN-02: never sign something other than what the lease describes.
        let sourceHash = try RequestSigner.sha256(fileAt: source)
        guard sourceHash == job.source.sha256.lowercased() else {
            throw RunnerError.job(code: "SOURCE_HASH_MISMATCH", message: "Downloaded \(sourceHash), lease says \(job.source.sha256)", retryable: true)
        }
        guard identity.identity.sha1 == job.certificateSHA1.uppercased() else {
            throw RunnerError.job(code: "CERTIFICATE_NOT_HELD", message: "Lease wants \(job.certificateSHA1)", retryable: true)
        }

        // Dylibs the backend asks us to inject (RuStoreCompat). Written once; the key
        // keeps an injected signing tree separate from a plain one and versions the shim.
        let injected = try Self.prepareInjected(job.injectDylibs, workDirectory: workDirectory)

        let workspace: RunnerCache.Workspace?
        if let cache {
            do {
                workspace = try RunnerCache.workspace(source: source, job: job, zsign: zsign, injectKey: injected.map(\.key), settings: cache)
            } catch {
                Log.info("signing cache unavailable; using an isolated job folder")
                workspace = nil
            }
        } else {
            workspace = nil
        }
        let unpacked = workDirectory.appendingPathComponent("unpacked", isDirectory: true)
        let app = try workspace?.app ?? Self.unpack(source, into: unpacked, code: "UNPACK_FAILED")

        // Every extension is signed with its own profile, so each one must be in the lease.
        try Self.checkNested(app: app, leased: job.nested)

        // Re-identify the app and its extensions to the bundle IDs the profiles were made for.
        try Self.setBundleIdentifier(job.bundleIdentifier, bundle: app)
        for nested in job.nested {
            try Self.setBundleIdentifier(nested.bundleIdentifier, bundle: app.appendingPathComponent(nested.path))
        }

        let profileData = try Self.decodeProfile(job.profile)
        let profileFile = workDirectory.appendingPathComponent("profile.mobileprovision")
        try profileData.write(to: profileFile)

        // zsign 1.1.2's warm hash cache seals the already signed main executable
        // as a resource. Reuse downloads and unpacked trees, but rebuild seals.
        var arguments = ["-f", "-z", String(zipLevel), "-k", identity.privateKey.path, "-c", identity.certificate.path, "-m", profileFile.path]
        if job.nested.isEmpty {
            let entitlements = try Self.entitlements(fromProfile: profileData, bundleIdentifier: job.bundleIdentifier, teamIdentifier: job.teamIdentifier)
            let entitlementsFile = workDirectory.appendingPathComponent("entitlements.plist")
            try PropertyListSerialization.data(fromPropertyList: entitlements, format: .xml, options: 0).write(to: entitlementsFile)
            arguments += ["-e", entitlementsFile.path]
        } else {
            // zsign matches each profile to the bundle whose ID it was made for. No -e here:
            // one entitlements file would be applied to every bundle, giving the extensions
            // the app's application-identifier. Each bundle takes its profile's entitlements.
            try Self.checkProfile(profileData, bundleIdentifier: job.bundleIdentifier, teamIdentifier: job.teamIdentifier)
            for (index, nested) in job.nested.enumerated() {
                let data = try Self.decodeProfile(nested.profile)
                try Self.checkProfile(data, bundleIdentifier: nested.bundleIdentifier, teamIdentifier: job.teamIdentifier)
                let file = workDirectory.appendingPathComponent("nested-\(index).mobileprovision")
                try data.write(to: file)
                arguments += ["-m", file.path]
            }
        }

        // Inject only when the tree is fresh: zsign modifies the unpacked app in place, so a
        // warm tree already carries the dylib and its load command from the cold run that
        // built this (inject-keyed) entry; re-injecting would add a second load command.
        if workspace?.hit != true {
            for dylib in injected {
                arguments += [dylib.weak ? "-w" : "-l", dylib.file.path]
            }
        }

        let output = workDirectory.appendingPathComponent("signed.ipa")
        let signingStarted = Date()
        let zsigned = try Shell.run(zsign, arguments + ["-o", output.path, app.path], in: workspace?.directory ?? workDirectory)
        let signingSeconds = Date().timeIntervalSince(signingStarted)
        guard zsigned.status == 0, FileManager.default.fileExists(atPath: output.path) else {
            throw RunnerError.job(code: "CODESIGN_FAILED", message: Self.zsignError(zsigned.output + zsigned.error), retryable: false)
        }

        // Check what will be uploaded, not the folder zsign worked in.
        let verificationStarted = Date()
        let checked = try Self.unpack(output, into: workDirectory.appendingPathComponent("check", isDirectory: true), code: "REPACK_FAILED")
        let signature = try Self.verify(app: checked, job: job, profile: profileData, workDirectory: workDirectory)
        let verificationSeconds = Date().timeIntervalSince(verificationStarted)
        let sha256 = try RequestSigner.sha256(fileAt: output)
        try workspace?.commit()
        let cleanLog = zsigned.output.replacingOccurrences(of: #"\x1B\[[0-9;]*[A-Za-z]"#, with: "", options: .regularExpression)

        return Output(ipa: output, sha256: sha256, report: [
            "signer": "zsign",
            "zip_level": String(zipLevel),
            "codesign": signature,
            "duration_seconds": String(format: "%.1f", Date().timeIntervalSince(started)),
            "profile_uuid": job.profile.uuid,
            "workspace_cache": workspace?.hit == true ? "hit" : "miss",
            "zsign_cache": cleanLog.range(of: #"ReadCache:\s*YES"#, options: .regularExpression) != nil ? "hit" : "miss",
            "resource_seal": "verified",
            "signature_policy": "fresh-resource-seal-v1",
            "zsign_seconds": String(format: "%.1f", signingSeconds),
            "verification_seconds": String(format: "%.1f", verificationSeconds),
            "injected_dylibs": injected.map(\.name).sorted().joined(separator: ","),
        ])
    }

    struct InjectedDylib { let name: String; let file: URL; let weak: Bool; let key: String }

    /// Writes each injected dylib to a private folder under its own basename (zsign copies it
    /// into the app by that name), and returns a stable per-dylib key for the cache.
    static func prepareInjected(_ dylibs: [SigningJob.InjectDylib], workDirectory: URL) throws -> [InjectedDylib] {
        guard !dylibs.isEmpty else { return [] }
        let folder = workDirectory.appendingPathComponent("inject", isDirectory: true)
        try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        return try dylibs.map { dylib in
            guard dylib.name.hasSuffix(".dylib"), !dylib.name.contains("/"), !dylib.name.contains(".."), !dylib.name.hasPrefix(".") else {
                throw RunnerError.job(code: "INJECT_DYLIB_INVALID", message: "Bad inject dylib name \(dylib.name)", retryable: false)
            }
            guard let data = Data(base64Encoded: dylib.content), !data.isEmpty, data.count < 64 * 1024 * 1024 else {
                throw RunnerError.job(code: "INJECT_DYLIB_INVALID", message: "Inject dylib \(dylib.name) is not valid base64", retryable: false)
            }
            let file = folder.appendingPathComponent(dylib.name)
            try data.write(to: file)
            return InjectedDylib(name: dylib.name, file: file, weak: dylib.weak == true, key: "\(dylib.name):\(RequestSigner.sha256(data))")
        }
    }

    /// Checks code/CMS hashes, sealed resources and the lease requirements.
    /// Apple evaluates certificate trust and platform requirements on the device.
    /// The profile is the leased one, and the app and
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
            if let bundle { try ResourceSeal.verify(bundle: bundle) }
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

        func checkFrameworks(of bundle: URL) throws -> Int {
            let code = try nestedCode(in: bundle)
            for item in code {
                if item.pathExtension == "framework" {
                    let frameworkInfo = try PropertyListSerialization.propertyList(from: Data(contentsOf: item.appendingPathComponent("Info.plist")), format: nil) as? [String: Any]
                    let name = frameworkInfo?["CFBundleExecutable"] as? String ?? item.deletingPathExtension().lastPathComponent
                    _ = try check(item.appendingPathComponent(name), bundle: item)
                } else {
                    _ = try check(item, bundle: nil)
                }
            }
            return code.count
        }
        var nestedCount = try checkFrameworks(of: app)

        // Each extension: its own leased profile, identifier and application-identifier.
        for nested in job.nested {
            let bundle = app.appendingPathComponent(nested.path)
            guard (try? Data(contentsOf: bundle.appendingPathComponent("embedded.mobileprovision"))) == Data(base64Encoded: nested.profile.content) else {
                throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "\(nested.path) does not embed its leased profile", retryable: false)
            }
            let bundleInfo = try PropertyListSerialization.propertyList(from: Data(contentsOf: bundle.appendingPathComponent("Info.plist")), format: nil) as? [String: Any]
            guard let bundleExecutable = bundleInfo?["CFBundleExecutable"] as? String else {
                throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "\(nested.path) has no CFBundleExecutable", retryable: false)
            }
            let signature = try check(bundle.appendingPathComponent(bundleExecutable), bundle: bundle)
            guard signature.identifier == nested.bundleIdentifier else {
                throw RunnerError.job(code: "CODESIGN_VERIFY_FAILED", message: "\(nested.path) is signed as \(signature.identifier), lease says \(nested.bundleIdentifier)", retryable: false)
            }
            let nestedEntitlements = try signature.entitlements.flatMap { try PropertyListSerialization.propertyList(from: $0, format: nil) as? [String: Any] }
            guard nestedEntitlements?["application-identifier"] as? String == "\(job.teamIdentifier).\(nested.bundleIdentifier)" else {
                throw RunnerError.job(code: "ENTITLEMENTS_MISMATCH", message: "\(nested.path) application-identifier is not \(job.teamIdentifier).\(nested.bundleIdentifier)", retryable: false)
            }
            nestedCount += 1 + (try checkFrameworks(of: bundle))
        }

        return "Identifier=\(main.identifier); TeamIdentifier=\(job.teamIdentifier); Authority=\(certificate); CDHash=\(main.cdHash); Nested=\(nestedCount); Extensions=\(job.nested.count)"
    }

    /// Refuses bundles that cannot be signed from this lease: watch apps and App Clips
    /// (not supported), and any extension the lease has no profile for.
    static func checkNested(app: URL, leased: [SigningJob.Nested]) throws {
        let files = FileManager.default
        for folder in ["Watch", "AppClips"] where files.fileExists(atPath: app.appendingPathComponent(folder).path) {
            throw RunnerError.job(code: "NESTED_PROFILE_REQUIRED", message: "\(folder)/ bundles are not supported", retryable: false)
        }

        let paths = Set(leased.map(\.path))
        for nested in leased {
            let parts = nested.path.split(separator: "/")
            guard parts.count == 2, ["PlugIns", "Extensions"].contains(String(parts[0])), parts[1].hasSuffix(".appex"),
                  files.fileExists(atPath: app.appendingPathComponent(nested.path).appendingPathComponent("Info.plist").path) else {
                throw RunnerError.job(code: "NESTED_INVALID", message: "Leased extension \(nested.path) is not in the app", retryable: false)
            }
        }
        for folder in ["PlugIns", "Extensions"] {
            let directory = app.appendingPathComponent(folder, isDirectory: true)
            guard files.fileExists(atPath: directory.path) else { continue }
            for item in try files.contentsOfDirectory(atPath: directory.path).sorted() where !paths.contains("\(folder)/\(item)") {
                let bundle = directory.appendingPathComponent(item)
                if item.hasSuffix(".bundle"),
                   let data = try? Data(contentsOf: bundle.appendingPathComponent("Info.plist")),
                   let info = try? PropertyListSerialization.propertyList(from: data, format: nil) as? [String: Any],
                   info["CFBundlePackageType"] as? String == "BNDL",
                   info["CFBundleExecutable"] == nil,
                   info["NSExtension"] == nil {
                    continue
                }
                throw RunnerError.job(code: "NESTED_PROFILE_REQUIRED", message: "\(folder)/\(item) has no profile in the lease", retryable: false)
            }
        }
    }

    /// Sets CFBundleIdentifier, keeping the Info.plist's own format (binary or XML).
    static func setBundleIdentifier(_ identifier: String, bundle: URL) throws {
        let file = bundle.appendingPathComponent("Info.plist")
        var format = PropertyListSerialization.PropertyListFormat.xml
        guard var info = try PropertyListSerialization.propertyList(from: Data(contentsOf: file), options: [], format: &format) as? [String: Any] else {
            throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "\(bundle.lastPathComponent)/Info.plist is not a dictionary", retryable: false)
        }
        guard info["CFBundleIdentifier"] as? String != identifier else { return }
        info["CFBundleIdentifier"] = identifier
        try PropertyListSerialization.data(fromPropertyList: info, format: format, options: 0).write(to: file)
    }

    static func decodeProfile(_ profile: SigningJob.Profile) throws -> Data {
        guard let data = Data(base64Encoded: profile.content) else {
            throw RunnerError.job(code: "PROFILE_INVALID", message: "Profile \(profile.uuid) is not base64", retryable: false)
        }
        return data
    }

    /// A profile zsign will pick by bundle ID must be for exactly that bundle and team.
    static func checkProfile(_ data: Data, bundleIdentifier: String, teamIdentifier: String) throws {
        guard let profile = try PropertyListSerialization.propertyList(from: profilePlist(data), format: nil) as? [String: Any],
              let entitlements = profile["Entitlements"] as? [String: Any] else {
            throw RunnerError.job(code: "PROFILE_INVALID", message: "Profile has no Entitlements", retryable: false)
        }
        guard (profile["TeamIdentifier"] as? [String] ?? []).contains(teamIdentifier) else {
            throw RunnerError.job(code: "TEAM_MISMATCH", message: "Profile for \(bundleIdentifier) is not for team \(teamIdentifier)", retryable: false)
        }
        guard entitlements["application-identifier"] as? String == "\(teamIdentifier).\(bundleIdentifier)" else {
            throw RunnerError.job(code: "PROFILE_MISMATCH", message: "Profile is for \(entitlements["application-identifier"] ?? "?"), not \(teamIdentifier).\(bundleIdentifier)", retryable: false)
        }
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
