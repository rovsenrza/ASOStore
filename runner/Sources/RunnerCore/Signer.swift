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
        /// The file to upload: the signed IPA, or a delta pack when `format` is set.
        public var ipa: URL
        public var sha256: String
        public var report: [String: String]
        /// `DeltaPack.format` / `DeltaPack.formatV2` when `ipa` is a pack built from the signed tree;
        /// nil for a full IPA.
        public var format: String? = nil
        /// Delta v2 only: a core built by this run, to upload before the pack.
        public var core: DeltaPack.CoreUpload? = nil
        /// Delta v2 only: the signed tree (the folder holding Payload/), kept for the v1 fallback
        /// when the core or the pack upload fails.
        public var deltaTree: URL? = nil
    }

    /// `sign` on a dedicated thread, for async callers: zsign, unzip and openssl block their
    /// thread for minutes. Setting `cancellation` stops the running tool and fails the sign.
    public func signDetached(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL, cancellation: Cancellation? = nil) async throws -> Output {
        try await Blocking.run(cancellation: cancellation) { [self] in
            try sign(job: job, identity: identity, source: source, workDirectory: workDirectory)
        }
    }

    /// `signDelta` on a dedicated thread, for async callers.
    public func signDeltaDetached(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL, cancellation: Cancellation? = nil) async throws -> Output {
        try await Blocking.run(cancellation: cancellation) { [self] in
            try signDelta(job: job, identity: identity, source: source, workDirectory: workDirectory)
        }
    }

    /// `fallbackFromDeltaV2` on a dedicated thread, for async callers.
    public func fallbackFromDeltaV2Detached(_ previous: Output, reason: String, job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL, cancellation: Cancellation? = nil) async throws -> Output {
        try await Blocking.run(cancellation: cancellation) { [self] in
            try fallbackFromDeltaV2(previous, reason: reason, job: job, identity: identity, source: source, workDirectory: workDirectory)
        }
    }

    static func size(of file: URL) -> Int64 {
        (try? FileManager.default.attributesOfItem(atPath: file.path)[.size] as? NSNumber)?.int64Value ?? 0
    }

    public func sign(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> Output {
        try zipBuild(try prepare(job: job, identity: identity, source: source, workDirectory: workDirectory),
                     job: job, source: source, workDirectory: workDirectory)
    }

    /// Everything before zsign runs, shared by the IPA path and the delta path.
    struct Prepared {
        var started: Date
        var workspace: RunnerCache.Workspace?
        var app: URL
        var profileData: Data
        /// zsign's arguments after `-f` (and `-z LEVEL` for the IPA path), before the injection ones.
        var common: [String]
        /// `-l`/`-w`/`-P`: only for a fresh tree (a warm one already carries the dylibs).
        var injection: [String]
        var injected: [InjectedDylib]
        var teamPrefixRewrites: Int
        /// Where zsign runs (it keeps its hash cache there).
        var directory: URL
    }

    func prepare(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> Prepared {
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

        // The one-time bootstrap claim is not part of the key: it is written into Info.plist
        // below on every run, cold or warm, before zsign signs (and seals Info.plist), and
        // removed when the job has none, so a claim never outlives the build it was made for.
        let cacheKey = injected.map(\.key)
            + (job.injectIntoExtensions && !injected.isEmpty ? ["inject-extensions"] : [])
            + (job.originalBundleIdentifier.map { ["original:" + $0] } ?? [])
            + (job.originalTeamIdentifier.map { ["team:" + $0] } ?? [])

        let workspace: RunnerCache.Workspace?
        // A delta build's tree is not kept: it belongs to one team and bundle ID, so a multi-gigabyte app
        // would leave one copy per team in the cache and push out the cached sources, and unpacking it
        // again is far cheaper than downloading the source again.
        if let cache, !job.delta.enabled {
            do {
                workspace = try RunnerCache.workspace(source: source, job: job, zsign: zsign, injectKey: cacheKey, settings: cache)
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
        // Record the vendor's bundle ID for the compat shim (listed apps only).
        if let original = job.originalBundleIdentifier {
            guard original.range(of: #"^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$"#, options: .regularExpression) != nil else {
                throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "Refusing original bundle ID \(original)", retryable: false)
            }
            try Self.setInfoValue(original, forKey: "RuStoreOriginalBundleIdentifier", bundle: app)
        }
        // Values the app took from its build's team ID name groups our signature does not own.
        var teamPrefixRewrites = 0
        if let originalTeam = job.originalTeamIdentifier {
            guard Self.isTeamIdentifier(originalTeam) else {
                throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "Refusing original team ID \(originalTeam)", retryable: false)
            }
            teamPrefixRewrites += try Self.rewriteTeamPrefix(from: originalTeam, to: job.teamIdentifier, bundle: app)
            for nested in job.nested {
                teamPrefixRewrites += try Self.rewriteTeamPrefix(from: originalTeam, to: job.teamIdentifier, bundle: app.appendingPathComponent(nested.path))
            }
        }
        try Self.ensureFaceIDUsageDescription(bundle: app)
        for nested in job.nested {
            try Self.ensureFaceIDUsageDescription(bundle: app.appendingPathComponent(nested.path))
        }
        for bundle in Self.codeBundles(app: app, nested: job.nested) {
            try Self.removeStraySignatureFiles(bundle: bundle)
        }
        // Embed the one-time login code (storefront app only) so first launch signs the customer in.
        if let claim = job.bootstrapClaim {
            try Self.setInfoValue(claim, forKey: "StorefrontBootstrapClaim", bundle: app)
        } else {
            try Self.removeInfoValue(forKey: "StorefrontBootstrapClaim", bundle: app)
        }

        let profileData = try Self.decodeProfile(job.profile)
        let profileFile = workDirectory.appendingPathComponent("profile.mobileprovision")
        try profileData.write(to: profileFile)
        // zsign embeds a single -m profile into the main app only and leaves each extension's old
        // embedded.mobileprovision (another team's): put the one external profile there first.
        if job.singleProfile {
            for nested in job.nested {
                try profileData.write(to: app.appendingPathComponent(nested.path).appendingPathComponent("embedded.mobileprovision"))
            }
        }

        // zsign 1.1.2's warm hash cache seals the already signed main executable
        // as a resource. Reuse downloads and unpacked trees, but rebuild seals.
        var arguments = ["-k", identity.privateKey.path, "-c", identity.certificate.path, "-m", profileFile.path]
        if job.singleProfile {
            // An external profile's explicit App ID is not the bundle's: iOS only accepts entitlements the
            // profile grants, so no -e — zsign gives every bundle the profile's own entitlements unchanged.
            _ = try Self.profilePlist(profileData)
        } else if job.nested.isEmpty {
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
        var injection: [String] = []
        if workspace?.hit != true {
            for dylib in injected {
                injection += [dylib.weak ? "-w" : "-l", dylib.file.path]
            }
            // A VPN's packet-tunnel extension needs the App-Group shim in its own process too.
            if job.injectIntoExtensions, !injected.isEmpty {
                injection += ["-P"]
            }
        }
        return Prepared(started: started, workspace: workspace, app: app, profileData: profileData, common: arguments, injection: injection,
                        injected: injected, teamPrefixRewrites: teamPrefixRewrites, directory: workspace?.directory ?? workDirectory)
    }

    /// zsign with `-o`: signs the tree and zips it; then the zip is unpacked and verified.
    func zipBuild(_ prepared: Prepared, job: SigningJob, source: URL, workDirectory: URL) throws -> Output {
        let (workspace, app, profileData) = (prepared.workspace, prepared.app, prepared.profileData)
        let arguments = ["-f", "-z", String(zipLevel)] + prepared.common + prepared.injection
        let output = workDirectory.appendingPathComponent("signed.ipa")
        let signingStarted = Date()
        let zsigned = try Shell.run(zsign, arguments + ["-o", output.path, app.path], in: prepared.directory,
                                    timeout: Shell.timeout(forBytes: max(Int64(job.source.sizeBytes), Self.size(of: source))))
        let signingSeconds = Date().timeIntervalSince(signingStarted)
        guard zsigned.status == 0, FileManager.default.fileExists(atPath: output.path) else {
            // Killed by a signal (OOM killer, container stop) is worth another try; a zsign error is not.
            throw RunnerError.job(code: "CODESIGN_FAILED", message: Shell.truncated(Self.zsignError(zsigned.output + zsigned.error)), retryable: zsigned.killedBySignal)
        }

        // Non-ASCII names (Payload/Кинодом.app) must read as UTF-8 here and on the device.
        try ZipNames.markUTF8(output)

        // Check what will be uploaded, not the folder zsign worked in.
        let verificationStarted = Date()
        let checked = try Self.unpack(output, into: workDirectory.appendingPathComponent("check", isDirectory: true), code: "REPACK_FAILED")
        let signature = try Self.verify(app: checked, job: job, profile: profileData, workDirectory: workDirectory)
        let verificationSeconds = Date().timeIntervalSince(verificationStarted)
        let sha256 = try RequestSigner.sha256(fileAt: output)
        try workspace?.commit()
        var report = Self.report(job, prepared, signature: signature, zsignSeconds: signingSeconds, verificationSeconds: verificationSeconds, cleanLog: Self.cleanLog(zsigned.output))
        report["zip_level"] = String(zipLevel)
        return Output(ipa: output, sha256: sha256, report: report)
    }

    /// The delta path (DeltaPack.buildFromTree): zsign signs the unpacked app in place (no `-o`, no
    /// `-z`: nothing is zipped), the tree itself is verified, and the pack is cut from it. When the
    /// pack cannot be used, the IPA path runs from the start (the cached tree, now signed, is a
    /// warm hit; without a cache the job folder is unpacked again) and its IPA is the result, so a
    /// delta that does not work never fails a job. A zsign or verification failure is a signing
    /// failure, as on the IPA path.
    public func signDelta(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> Output {
        let reason: String
        switch try deltaAttempt(job: job, identity: identity, source: source, workDirectory: workDirectory) {
        case .built(let output): return output
        case .fallback(let why): reason = why
        }
        Log.info("job \(job.jobID) delta from the signed tree unavailable (\(reason)); building the full IPA")
        // The attempt's workspace lock is released (it went out of scope). Without a cache the
        // job folder holds the tree the attempt signed and zsign's cache: start those afresh.
        try? FileManager.default.removeItem(at: workDirectory.appendingPathComponent("unpacked", isDirectory: true))
        try? FileManager.default.removeItem(at: workDirectory.appendingPathComponent(".zsign_cache", isDirectory: true))
        var output = try sign(job: job, identity: identity, source: source, workDirectory: workDirectory)
        output.report["delta_fallback"] = Shell.truncated(reason, limit: 200)
        return output
    }

    private enum DeltaAttempt {
        case built(Output)
        case fallback(String)
    }

    private func deltaAttempt(job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> DeltaAttempt {
        let prepared = try prepare(job: job, identity: identity, source: source, workDirectory: workDirectory)

        let signingStarted = Date()
        let zsigned = try Shell.run(zsign, ["-f"] + prepared.common + prepared.injection + [prepared.app.path], in: prepared.directory,
                                    timeout: Shell.timeout(forBytes: max(Int64(job.source.sizeBytes), Self.size(of: source))))
        let signingSeconds = Date().timeIntervalSince(signingStarted)
        guard zsigned.status == 0 else {
            throw RunnerError.job(code: "CODESIGN_FAILED", message: Shell.truncated(Self.zsignError(zsigned.output + zsigned.error)), retryable: zsigned.killedBySignal)
        }

        // The signed folder is what the pack is cut from: check it, not an IPA.
        let verificationStarted = Date()
        let signature = try Self.verify(app: prepared.app, job: job, profile: prepared.profileData, workDirectory: workDirectory)
        let verificationSeconds = Date().timeIntervalSince(verificationStarted)
        try prepared.workspace?.commit()

        let pack = workDirectory.appendingPathComponent("delta.pack")
        let treeRoot = prepared.app.deletingLastPathComponent().deletingLastPathComponent()
        var report = Self.report(job, prepared, signature: signature, zsignSeconds: signingSeconds, verificationSeconds: verificationSeconds, cleanLog: Self.cleanLog(zsigned.output))

        // Delta v2 first when the lease asks for it; any trouble there ends in the v1 tree pack.
        if job.delta.version == 2 {
            let v2Reason: String
            switch Self.coreSource(job, workDirectory: workDirectory) {
            case .failure(let why):
                v2Reason = why.reason
            case .success(let core):
                switch DeltaPack.buildV2FromTree(source: source, treeRoot: treeRoot, output: pack, baseSHA256: job.source.sha256, core: core) {
                case .pack(let built):
                    report.merge(built.report) { _, new in new }
                    Log.info("job \(job.jobID) delta-v2 pack: \(built.entriesSegmented) segmented + \(built.pack.entriesPack - built.entriesSegmented) whole of \(built.pack.entriesTotal) entries, \(built.pack.packBytes) of \(built.pack.signedBytes) bytes, core \(report["core"] ?? "")")
                    return .built(Output(ipa: pack, sha256: built.pack.sha256, report: report, format: DeltaPack.formatV2, core: built.core, deltaTree: treeRoot))
                case .fallback(let why):
                    v2Reason = why
                }
            }
            Log.info("job \(job.jobID) delta-v2 unavailable (\(v2Reason)); trying the delta-v1 tree pack")
            report["delta_v2_fallback"] = Shell.truncated(v2Reason, limit: 200)
        }

        switch DeltaPack.buildFromTree(source: source, treeRoot: treeRoot, output: pack, baseSHA256: job.source.sha256) {
        case .pack(let built):
            report.merge(built.report) { _, new in new }
            Log.info("job \(job.jobID) delta pack from the signed tree: \(built.entriesPack)/\(built.entriesTotal) entries, \(built.packBytes) of \(built.signedBytes) bytes")
            return .built(Output(ipa: pack, sha256: built.sha256, report: report, format: DeltaPack.format))
        case .fallback(let reason):
            return .fallback(reason)
        }
    }

    /// Where a delta-v2 pack's core comes from: the lease's core, or a new one built into the job
    /// folder (only when the lease says where to upload it).
    static func coreSource(_ job: SigningJob, workDirectory: URL) -> Swift.Result<DeltaPack.CoreSource, DeltaPack.Unusable> {
        if job.delta.coreUnreadable { return .failure(.init("the lease's core could not be read")) }
        if let core = job.delta.core { return .success(.reuse(sha256: core.sha256, manifest: core.manifest)) }
        guard let path = job.delta.corePath, !path.isEmpty else { return .failure(.init("the lease has no core and no core_path")) }
        return .success(.build(output: workDirectory.appendingPathComponent("delta-core.bin")))
    }

    /// Report keys only a delta-v2 pack has.
    static let deltaV2ReportKeys = ["delta_format", "entries_segmented", "team_bytes", "core", "core_bytes"]

    /// After a delta-v2 pack could not be delivered (core or pack upload failed): the delta-v1 pack
    /// from the same signed tree, else the full IPA. Never fails because of delta.
    public func fallbackFromDeltaV2(_ previous: Output, reason: String, job: SigningJob, identity: IdentityFiles, source: URL, workDirectory: URL) throws -> Output {
        var report = previous.report
        for key in Self.deltaV2ReportKeys { report.removeValue(forKey: key) }
        report["delta_v2_fallback"] = Shell.truncated(reason, limit: 200)
        try? FileManager.default.removeItem(at: previous.ipa)
        if let core = previous.core { try? FileManager.default.removeItem(at: core.blob) }

        var v1Reason = "no signed tree"
        if let tree = previous.deltaTree {
            let pack = workDirectory.appendingPathComponent("delta-v1.pack")
            switch DeltaPack.buildFromTree(source: source, treeRoot: tree, output: pack, baseSHA256: job.source.sha256) {
            case .pack(let built):
                report.merge(built.report) { _, new in new }
                Log.info("job \(job.jobID) delta-v2 not delivered (\(reason)); using the delta-v1 pack: \(built.packBytes) of \(built.signedBytes) bytes")
                return Output(ipa: pack, sha256: built.sha256, report: report, format: DeltaPack.format)
            case .fallback(let why):
                v1Reason = why
            }
        }
        Log.info("job \(job.jobID) delta-v2 not delivered (\(reason)) and no delta-v1 pack (\(v1Reason)); building the full IPA")
        try? FileManager.default.removeItem(at: workDirectory.appendingPathComponent("unpacked", isDirectory: true))
        try? FileManager.default.removeItem(at: workDirectory.appendingPathComponent(".zsign_cache", isDirectory: true))
        var output = try sign(job: job, identity: identity, source: source, workDirectory: workDirectory)
        output.report["delta_fallback"] = Shell.truncated(v1Reason, limit: 200)
        output.report["delta_v2_fallback"] = Shell.truncated(reason, limit: 200)
        return output
    }

    static func cleanLog(_ output: String) -> String {
        output.replacingOccurrences(of: #"\x1B\[[0-9;]*[A-Za-z]"#, with: "", options: .regularExpression)
    }

    /// The report keys both paths share.
    static func report(_ job: SigningJob, _ prepared: Prepared, signature: String, zsignSeconds: Double, verificationSeconds: Double, cleanLog: String) -> [String: String] {
        [
            "signer": "zsign",
            "codesign": signature,
            "duration_seconds": String(format: "%.1f", Date().timeIntervalSince(prepared.started)),
            "profile_uuid": job.profile.uuid,
            "workspace_cache": prepared.workspace?.hit == true ? "hit" : "miss",
            "zsign_cache": cleanLog.range(of: #"ReadCache:\s*YES"#, options: .regularExpression) != nil ? "hit" : "miss",
            "resource_seal": "verified",
            "signature_policy": "fresh-resource-seal-v1",
            "zsign_seconds": String(format: "%.1f", zsignSeconds),
            "verification_seconds": String(format: "%.1f", verificationSeconds),
            "injected_dylibs": prepared.injected.map(\.name).sorted().joined(separator: ","),
            "bootstrap_embedded": job.bootstrapClaim == nil ? "no" : "yes",
            "team_prefix_rewrites": String(prepared.teamPrefixRewrites),
        ]
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
        guard job.singleProfile || signedEntitlements?["application-identifier"] as? String == "\(job.teamIdentifier).\(job.bundleIdentifier)" else {
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
            guard job.singleProfile || nestedEntitlements?["application-identifier"] as? String == "\(job.teamIdentifier).\(nested.bundleIdentifier)" else {
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
                // DynamicBundleRuntime stores its DBR1/DBH1 data archive here. It is
                // sealed as a resource, not provisioned as an app extension.
                if folder == "PlugIns", item == "DynamicBundle.dat", try isDynamicBundleData(bundle) {
                    continue
                }
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

    private static func isDynamicBundleData(_ file: URL) throws -> Bool {
        let attributes = try FileManager.default.attributesOfItem(atPath: file.path)
        guard attributes[.type] as? FileAttributeType == .typeRegular,
              let permissions = attributes[.posixPermissions] as? NSNumber,
              permissions.intValue & 0o111 == 0 else { return false }
        let handle = try FileHandle(forReadingFrom: file)
        defer { try? handle.close() }
        guard let header = try handle.read(upToCount: 12), header.count == 12 else { return false }
        return header.prefix(4) == Data("DBR1".utf8) && header.suffix(4) == Data("DBH1".utf8)
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

    /// Writes a bounded string value into the app's Info.plist (the one-time StorefrontBootstrapClaim),
    /// keeping the plist's own format. The key is restricted to letters so the lease cannot set
    /// arbitrary Info.plist entries, and the value is size-capped.
    static func setInfoValue(_ value: String, forKey key: String, bundle: URL) throws {
        guard value.utf8.count <= 4096, !key.isEmpty, key.allSatisfy(\.isLetter) else {
            throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "Refusing to embed \(key)", retryable: false)
        }
        let file = bundle.appendingPathComponent("Info.plist")
        var format = PropertyListSerialization.PropertyListFormat.xml
        guard var info = try PropertyListSerialization.propertyList(from: Data(contentsOf: file), options: [], format: &format) as? [String: Any] else {
            throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "\(bundle.lastPathComponent)/Info.plist is not a dictionary", retryable: false)
        }
        guard info[key] as? String != value else { return }
        info[key] = value
        try PropertyListSerialization.data(fromPropertyList: info, format: format, options: 0).write(to: file)
    }

    /// Removes an Info.plist key (a warm signing tree may still carry the previous job's claim).
    static func removeInfoValue(forKey key: String, bundle: URL) throws {
        let file = bundle.appendingPathComponent("Info.plist")
        var format = PropertyListSerialization.PropertyListFormat.xml
        guard var info = try PropertyListSerialization.propertyList(from: Data(contentsOf: file), options: [], format: &format) as? [String: Any] else {
            throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "\(bundle.lastPathComponent)/Info.plist is not a dictionary", retryable: false)
        }
        guard info.removeValue(forKey: key) != nil else { return }
        try PropertyListSerialization.data(fromPropertyList: info, format: format, options: 0).write(to: file)
    }

    static let faceIDUsageDescription = "Face ID используется для быстрого и безопасного входа."

    /// Adds NSFaceIDUsageDescription when the bundle has none. Our profile's `<TEAM>.*` keychain
    /// access lets a keychain read reach an item another app of the team protected with Face ID
    /// (VK's apps share their sign-in token); without the key iOS kills the app on that read
    /// (TCC abort) instead of asking. Returns whether the Info.plist changed.
    @discardableResult
    static func ensureFaceIDUsageDescription(bundle: URL) throws -> Bool {
        let file = bundle.appendingPathComponent("Info.plist")
        var format = PropertyListSerialization.PropertyListFormat.xml
        guard var info = try PropertyListSerialization.propertyList(from: Data(contentsOf: file), options: [], format: &format) as? [String: Any] else {
            throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "\(bundle.lastPathComponent)/Info.plist is not a dictionary", retryable: false)
        }
        guard (info["NSFaceIDUsageDescription"] as? String)?.isEmpty ?? true else { return false }
        info["NSFaceIDUsageDescription"] = faceIDUsageDescription
        try PropertyListSerialization.data(fromPropertyList: info, format: format, options: 0).write(to: file)
        return true
    }

    /// Deletes files a vendor left in a code bundle's own `_CodeSignature/` besides CodeResources (Халва ships
    /// a legacy `_CodeSignature/ResourceRules`). zsign seals them as resources, but iOS never counts that folder
    /// as resources, so the seal names a file it cannot find and installd refuses the app ("A signed resource has
    /// been added, modified, or deleted"). zsign writes CodeResources anew. Returns how many files went.
    @discardableResult
    static func removeStraySignatureFiles(bundle: URL) throws -> Int {
        let folder = bundle.appendingPathComponent("_CodeSignature", isDirectory: true)
        guard let names = try? FileManager.default.contentsOfDirectory(atPath: folder.path) else { return 0 }
        var removed = 0
        for name in names where name != "CodeResources" {
            try FileManager.default.removeItem(at: folder.appendingPathComponent(name))
            removed += 1
        }
        return removed
    }

    /// The app, its extensions and its frameworks: every code bundle zsign writes a seal for.
    static func codeBundles(app: URL, nested: [SigningJob.Nested]) -> [URL] {
        let frameworks = app.appendingPathComponent("Frameworks", isDirectory: true)
        let found = ((try? FileManager.default.contentsOfDirectory(atPath: frameworks.path)) ?? [])
            .filter { $0.hasSuffix(".framework") }
            .map { frameworks.appendingPathComponent($0, isDirectory: true) }
        return [app] + nested.map { app.appendingPathComponent($0.path, isDirectory: true) } + found
    }

    static func isTeamIdentifier(_ value: String) -> Bool {
        value.range(of: #"^[A-Z0-9]{10}$"#, options: .regularExpression) != nil
    }

    /// Re-prefixes Info.plist string values that start with `original + "."` (the team ID the vendor
    /// built the app with) by `team + "."`. An app that builds a keychain access group from its
    /// build-time team ID (Info.plist `KeychainAccessGroup = Q6L2SF6YDW.com.example.shared`) would
    /// otherwise ask for a group this signature does not own; under our team it falls inside the
    /// profile's `<TEAM>.*`. Returns how many values changed; keys and other values stay as they are.
    @discardableResult
    static func rewriteTeamPrefix(from original: String, to team: String, bundle: URL) throws -> Int {
        guard isTeamIdentifier(original), original != team else { return 0 }
        let file = bundle.appendingPathComponent("Info.plist")
        var format = PropertyListSerialization.PropertyListFormat.xml
        guard let info = try PropertyListSerialization.propertyList(from: Data(contentsOf: file), options: [], format: &format) as? [String: Any] else {
            throw RunnerError.job(code: "INFO_PLIST_INVALID", message: "\(bundle.lastPathComponent)/Info.plist is not a dictionary", retryable: false)
        }
        let prefix = original + "."
        var changed = 0
        func rewrite(_ value: Any) -> Any {
            switch value {
            case let string as String where string.hasPrefix(prefix):
                changed += 1
                return team + "." + string.dropFirst(prefix.count)
            case let dictionary as [String: Any]:
                return dictionary.mapValues(rewrite)
            case let array as [Any]:
                return array.map(rewrite)
            default:
                return value
            }
        }
        let updated = rewrite(info)
        guard changed > 0, let result = updated as? [String: Any] else { return 0 }
        try PropertyListSerialization.data(fromPropertyList: result, format: format, options: 0).write(to: file)
        return changed
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
        try Shell.require(code, "unzip", ["-qq", ipa.path, "-d", folder.path], timeout: Shell.timeout(forFile: ipa))
        let payload = folder.appendingPathComponent("Payload", isDirectory: true)
        let apps = (try? FileManager.default.contentsOfDirectory(at: payload, includingPropertiesForKeys: nil).filter { $0.pathExtension == "app" }) ?? []
        guard apps.count == 1, let app = apps.first else {
            throw RunnerError.job(code: "NO_APP_BUNDLE", message: "Expected exactly one Payload/*.app", retryable: false)
        }
        try checkSymlinks(in: folder, app: app)
        return app
    }

    /// Refuses an archive with a symbolic link that leads out of its app (e.g. to /etc or to
    /// the runner's keys): zsign and the verification would follow it. Links inside the app
    /// (framework Versions/Current) are fine. No link is ever followed here.
    static func checkSymlinks(in folder: URL, app: URL) throws {
        let root = app.standardizedFileURL.path
        let base = folder.standardizedFileURL.path
        func inside(_ path: String) -> Bool { path == root || path.hasPrefix(root + "/") }

        var pending = [base]
        while let directory = pending.popLast() {
            guard let names = try? FileManager.default.contentsOfDirectory(atPath: directory) else { continue }
            for name in names {
                let path = directory + "/" + name
                switch Self.fileType(path) {
                case .typeDirectory:
                    pending.append(path)
                case .typeSymbolicLink:
                    let relative = String(path.dropFirst(base.count + 1))
                    guard inside(path), let target = Self.resolveLink(path, root: root), inside(target) else {
                        throw RunnerError.job(code: "UNSAFE_SYMLINK", message: "\(relative) links outside the app", retryable: false)
                    }
                default:
                    continue
                }
            }
        }
    }

    /// The type of the item itself (attributesOfItem uses lstat: links are not followed).
    private static func fileType(_ path: String) -> FileAttributeType? {
        (try? FileManager.default.attributesOfItem(atPath: path))?[.type] as? FileAttributeType
    }

    /// The lexical target of a link, following further links inside the app (at most 32 hops).
    /// Nil for an absolute target or a chain that does not end.
    private static func resolveLink(_ path: String, root: String) -> String? {
        var current = path
        for _ in 0..<32 {
            guard let destination = try? FileManager.default.destinationOfSymbolicLink(atPath: current), !destination.hasPrefix("/") else { return nil }
            let parent = (current as NSString).deletingLastPathComponent
            current = URL(fileURLWithPath: parent + "/" + destination).standardizedFileURL.path
            guard current == root || current.hasPrefix(root + "/") else { return current }
            guard fileType(current) == .typeSymbolicLink else { return current }
        }
        return nil
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
