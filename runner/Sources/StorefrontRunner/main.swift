import Foundation
import RunnerCore

// storefront-runner: see runner/README.md.
//   storefront-runner                   run the lease loop (configured by environment)
//   storefront-runner --list-identities print the signing identities the backend will be told about
//   storefront-runner --sign-file IN.ipa --profile P.mobileprovision --bundle-id ID --out OUT.ipa
//                                       sign one file outside the queue, e.g. to install on a test
//                                       device after upgrading zsign
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

        let job = SigningJob(
            jobID: "local", signedBuildID: "local", bundleIdentifier: try value("--bundle-id"), teamIdentifier: team,
            certificateSHA1: identity.identity.sha1,
            profile: .init(uuid: plist?["UUID"] as? String ?? "", content: profile.base64EncodedString()),
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
