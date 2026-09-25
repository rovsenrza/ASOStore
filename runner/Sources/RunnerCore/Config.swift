import Foundation

/// Runner settings, read from the environment (see README and launchd/*.plist).
public struct RunnerConfig: Sendable {
    public var baseURL: URL
    public var keyID: String
    public var secret: String
    /// Private scratch space; every job's folder is deleted when it ends.
    public var workDirectory: URL
    /// Dedicated signing Keychain (P6-RUN-02). nil uses the login Keychain search list.
    public var keychain: String?
    public var pollInterval: Duration
    public var version: String

    public init(baseURL: URL, keyID: String, secret: String, workDirectory: URL, keychain: String?, pollInterval: Duration, version: String = RunnerConfig.currentVersion) {
        self.baseURL = baseURL
        self.keyID = keyID
        self.secret = secret
        self.workDirectory = workDirectory
        self.keychain = keychain
        self.pollInterval = pollInterval
        self.version = version
    }

    public static let currentVersion = "1.0.0"

    public static func fromEnvironment(_ env: [String: String] = ProcessInfo.processInfo.environment) throws -> RunnerConfig {
        func required(_ key: String) throws -> String {
            guard let value = env[key], !value.isEmpty else { throw RunnerError.configuration("\(key) is not set") }
            return value
        }
        guard let url = URL(string: try required("STOREFRONT_RUNNER_BASE_URL")), url.scheme == "https" || env["STOREFRONT_RUNNER_ALLOW_HTTP"] == "1" else {
            throw RunnerError.configuration("STOREFRONT_RUNNER_BASE_URL must be an https URL")
        }
        let work = env["STOREFRONT_RUNNER_WORK_DIR"].map { URL(fileURLWithPath: $0, isDirectory: true) }
            ?? FileManager.default.temporaryDirectory.appendingPathComponent("storefront-runner", isDirectory: true)

        return RunnerConfig(
            baseURL: url,
            keyID: try required("STOREFRONT_RUNNER_KEY_ID"),
            secret: try required("STOREFRONT_RUNNER_SECRET"),
            workDirectory: work,
            keychain: env["STOREFRONT_RUNNER_KEYCHAIN"].flatMap { $0.isEmpty ? nil : $0 },
            pollInterval: .seconds(Int(env["STOREFRONT_RUNNER_POLL_SECONDS"] ?? "") ?? 10)
        )
    }
}

public enum RunnerError: Error, CustomStringConvertible, Equatable {
    case configuration(String)
    case http(status: Int, body: String)
    /// A job failure the backend should see, with a stable code.
    case job(code: String, message: String, retryable: Bool)

    public var description: String {
        switch self {
        case .configuration(let message): "Configuration: \(message)"
        case .http(let status, let body): "HTTP \(status): \(body.prefix(300))"
        case .job(let code, let message, _): "\(code): \(message)"
        }
    }
}
