import Foundation
import RunnerCore

// storefront-runner: see runner/README.md.
//   storefront-runner                   run the lease loop (configured by environment)
//   storefront-runner --list-identities print the signing identities the backend will be told about
//   storefront-runner --sign-file IN.ipa --profile P.mobileprovision --bundle-id ID --out OUT.ipa
//                                       sign one file outside the queue, e.g. to install on a test
//                                       device after upgrading zsign
//   storefront-runner --build-pack SOURCE.ipa SIGNED.ipa OUT.pack
//                                       build a delta-v1 pack (DeltaPack) and print one JSON line:
//                                       the summary, or {"delta":false,"reason":…} when none is used
//   storefront-runner --build-pack-tree SOURCE.ipa TREE_ROOT OUT.pack
//                                       the same, cut from a signed folder that holds Payload/ (delta v2)
do {
    let arguments = CommandLine.arguments
    func value(_ flag: String) throws -> String {
        guard let index = arguments.firstIndex(of: flag), index + 1 < arguments.count else { throw RunnerError.configuration("\(flag) is required") }
        return arguments[index + 1]
    }
    func identities() throws -> [IdentityFiles] {
        guard let directory = ProcessInfo.processInfo.environment["STOREFRONT_RUNNER_IDENTITIES_DIR"], !directory.isEmpty else {
            throw RunnerError.configuration("STOREFRONT_RUNNER_IDENTITIES_DIR is not set")
        }
        return try Identities.load(directory: URL(fileURLWithPath: directory, isDirectory: true))
    }

    if arguments.contains("--list-identities") {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        print(String(decoding: try encoder.encode(identities().map(\.identity)), as: UTF8.self))
        exit(0)
    }

    // --build-pack SOURCE SIGNED OUT: a pack cut from a signed IPA; --build-pack-tree SOURCE TREE_ROOT OUT: from
    // the signed folder that holds Payload/. Both print one JSON line and exit 0.
    for flag in ["--build-pack", "--build-pack-tree"] {
        guard let index = arguments.firstIndex(of: flag) else { continue }
        guard index + 3 < arguments.count else { throw RunnerError.configuration("\(flag) needs SOURCE.ipa \(flag == "--build-pack" ? "SIGNED.ipa" : "TREE_ROOT") OUT.pack") }
        let source = URL(fileURLWithPath: arguments[index + 1])
        let signed = URL(fileURLWithPath: arguments[index + 2], isDirectory: flag == "--build-pack-tree")
        let destination = URL(fileURLWithPath: arguments[index + 3])
        let baseSHA256 = try RequestSigner.sha256(fileAt: source)
        let outcome = flag == "--build-pack"
            ? DeltaPack.build(source: source, signed: signed, output: destination, baseSHA256: baseSHA256)
            : DeltaPack.buildFromTree(source: source, treeRoot: signed, output: destination, baseSHA256: baseSHA256)
        var line: [String: Any]
        switch outcome {
        case .pack(let built):
            line = ["delta": true, "format": DeltaPack.format, "base_sha256": built.baseSHA256, "pack_sha256": built.sha256,
                    "pack_bytes": built.packBytes, "signed_bytes": built.signedBytes, "entries_total": built.entriesTotal,
                    "entries_pack": built.entriesPack, "delta_seconds": (built.seconds * 10).rounded() / 10]
        case .fallback(let reason):
            line = ["delta": false, "base_sha256": baseSHA256, "reason": reason]
        }
        print(String(decoding: try JSONSerialization.data(withJSONObject: line, options: [.sortedKeys]), as: UTF8.self))
        exit(0)
    }

    if arguments.contains("--sign-file") {
        let source = URL(fileURLWithPath: try value("--sign-file"))
        let profile = try Data(contentsOf: URL(fileURLWithPath: try value("--profile")))
        let destination = URL(fileURLWithPath: try value("--out"))

        // The profile names the team and the certificates allowed to sign for it.
        let plist = try PropertyListSerialization.propertyList(from: Signer.profilePlist(profile), format: nil) as? [String: Any]
        guard let team = (plist?["TeamIdentifier"] as? [String])?.first else { throw RunnerError.configuration("profile has no TeamIdentifier") }
        let allowed = Set(((plist?["DeveloperCertificates"] as? [Data]) ?? []).map { Digest.hash($0, .sha1).hex.uppercased() })
        guard let identity = try identities().first(where: { allowed.contains($0.identity.sha1) }) else {
            throw RunnerError.configuration("no identity in STOREFRONT_RUNNER_IDENTITIES_DIR matches the profile's certificates")
        }

        let work = FileManager.default.temporaryDirectory.appendingPathComponent("sign-file-\(UUID().uuidString)", isDirectory: true)
        try FileManager.default.createDirectory(at: work, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        defer { try? FileManager.default.removeItem(at: work) }
        // --inject PATH (repeatable): inject a dylib (e.g. the RuStoreCompat shim) like a lease would.
        var inject: [SigningJob.InjectDylib] = []
        var index = 0
        while let i = arguments[index...].firstIndex(of: "--inject"), i + 1 < arguments.count {
            let url = URL(fileURLWithPath: arguments[i + 1])
            inject.append(.init(name: url.lastPathComponent, content: try Data(contentsOf: url).base64EncodedString()))
            index = i + 2
        }

        // --nested PATH BUNDLE_ID PROFILE (repeatable): an extension inside the .app with its own profile.
        var nested: [SigningJob.Nested] = []
        var nestedIndex = 0
        while let i = arguments[nestedIndex...].firstIndex(of: "--nested"), i + 3 < arguments.count {
            let nestedProfile = try Data(contentsOf: URL(fileURLWithPath: arguments[i + 3]))
            let nestedPlist = try PropertyListSerialization.propertyList(from: Signer.profilePlist(nestedProfile), format: nil) as? [String: Any]
            nested.append(.init(path: arguments[i + 1], bundleIdentifier: arguments[i + 2],
                                profile: .init(uuid: nestedPlist?["UUID"] as? String ?? "", content: nestedProfile.base64EncodedString())))
            nestedIndex = i + 4
        }

        let job = SigningJob(
            jobID: "local", signedBuildID: "local", bundleIdentifier: try value("--bundle-id"), teamIdentifier: team,
            certificateSHA1: identity.identity.sha1,
            profile: .init(uuid: plist?["UUID"] as? String ?? "", content: profile.base64EncodedString()),
            nested: nested,
            injectDylibs: inject,
            source: .init(sha256: try RequestSigner.sha256(fileAt: source), sizeBytes: 0, path: ""),
            uploadPath: "", resultPath: ""
        )
        let cache = ProcessInfo.processInfo.environment["STOREFRONT_RUNNER_CACHE_ENABLED"] == "0" ? nil : ProcessInfo.processInfo.environment["STOREFRONT_RUNNER_CACHE_DIR"].map { RunnerCache.Settings(directory: URL(fileURLWithPath: $0, isDirectory: true)) }
        let output = try Signer(zsign: ProcessInfo.processInfo.environment["STOREFRONT_RUNNER_ZSIGN"] ?? "zsign", cache: cache).sign(job: job, identity: identity, source: source, workDirectory: work)
        try? FileManager.default.removeItem(at: destination)
        try FileManager.default.copyItem(at: output.ipa, to: destination)
        print(output.report.sorted { $0.key < $1.key }.map { "\($0.key): \($0.value)" }.joined(separator: "\n"))
        print("sha256: \(output.sha256)")
        exit(0)
    }

    let config = try RunnerConfig.fromEnvironment()
    // Fail at start-up, not on the first job, when a tool is missing.
    for tool in [config.zsign, "openssl", "unzip"] { _ = try Shell.locate(tool) }
    try FileManager.default.createDirectory(at: config.workDirectory, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
    // Folders of jobs that a crash or a container stop left behind, and lock files of
    // cache entries that are gone (pruned again after jobs, every ten minutes).
    let swept = RunnerCache.sweepWorkDirectory(config.workDirectory, keeping: config.cacheDirectory.map { [$0] } ?? [])
    let locks = config.cacheSettings.map { RunnerCache.pruneLocks(settings: $0, minimumAge: 0) } ?? 0
    Log.info("start-up clean-up: \(swept) stale job folders, \(locks) stale cache locks removed")
    let cache = config.cacheSettings.map { RunnerCache(settings: $0) }
    await withTaskGroup(of: Void.self) { group in
        for _ in 0..<config.concurrency {
            group.addTask { await Runner(config: config, cache: cache).run() }
        }
    }
} catch {
    FileHandle.standardError.write(Data("storefront-runner: \(error)\n".utf8))
    exit(1)
}
