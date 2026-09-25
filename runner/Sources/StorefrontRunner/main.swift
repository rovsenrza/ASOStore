import Foundation
import RunnerCore

// storefront-runner: see runner/README.md.
//   storefront-runner                   run the lease loop (configured by environment)
//   storefront-runner --list-identities print the signing identities the backend will be told about
do {
    if CommandLine.arguments.contains("--list-identities") {
        let keychain = ProcessInfo.processInfo.environment["STOREFRONT_RUNNER_KEYCHAIN"]
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        print(String(decoding: try encoder.encode(Identities.load(keychain: keychain?.isEmpty == false ? keychain : nil)), as: UTF8.self))
        exit(0)
    }

    let config = try RunnerConfig.fromEnvironment()
    try FileManager.default.createDirectory(at: config.workDirectory, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
    await Runner(config: config).run()
} catch {
    FileHandle.standardError.write(Data("storefront-runner: \(error)\n".utf8))
    exit(1)
}
