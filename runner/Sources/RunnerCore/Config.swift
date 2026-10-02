import Foundation

/// Runner settings, read from the environment (see README and Dockerfile).
public struct RunnerConfig: Sendable {
    public var baseURL: URL
    public var keyID: String
    public var secret: String
    /// Private scratch space; every job's folder is deleted when it ends.
    public var workDirectory: URL
    /// Folder holding each signing identity as `<name>.key` plus `<name>.cer`/`.pem` (P6-RUN-02).
    public var identitiesDirectory: URL
    /// zsign executable, a path or a name looked up in PATH.
    public var zsign: String
    public var zipLevel: Int
    public var concurrency: Int
    public var pollInterval: Duration
    public var version: String
    public var cacheDirectory: URL?
    public var cacheMaxBytes: Int64
    public var cacheTTL: TimeInterval

    public var cacheSettings: RunnerCache.Settings? {
        cacheDirectory.map { RunnerCache.Settings(directory: $0, maxBytes: cacheMaxBytes, ttl: cacheTTL) }
    }

    public init(baseURL: URL, keyID: String, secret: String, workDirectory: URL, identitiesDirectory: URL, zsign: String = "zsign", zipLevel: Int = 1, concurrency: Int = 2, pollInterval: Duration, version: String = RunnerConfig.currentVersion, cacheDirectory: URL? = nil, cacheMaxBytes: Int64 = 8 * 1024 * 1024 * 1024, cacheTTL: TimeInterval = 86_400) {
        self.baseURL = baseURL
        self.keyID = keyID
        self.secret = secret
        self.workDirectory = workDirectory
        self.identitiesDirectory = identitiesDirectory
        self.zsign = zsign
        self.zipLevel = zipLevel
        self.concurrency = concurrency
        self.pollInterval = pollInterval
        self.version = version
        self.cacheDirectory = cacheDirectory
        self.cacheMaxBytes = cacheMaxBytes
        self.cacheTTL = cacheTTL
    }

    public static let currentVersion = "2.2.0"

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

        func bounded(_ key: String, default fallback: Int, range: ClosedRange<Int>) throws -> Int {
            guard let raw = env[key], !raw.isEmpty else { return fallback }
            guard let value = Int(raw), range.contains(value) else {
                throw RunnerError.configuration("\(key) must be between \(range.lowerBound) and \(range.upperBound)")
            }
            return value
        }
        return RunnerConfig(
            baseURL: url,
            keyID: try required("STOREFRONT_RUNNER_KEY_ID"),
            secret: try required("STOREFRONT_RUNNER_SECRET"),
            workDirectory: work,
            identitiesDirectory: URL(fileURLWithPath: try required("STOREFRONT_RUNNER_IDENTITIES_DIR"), isDirectory: true),
            zsign: env["STOREFRONT_RUNNER_ZSIGN"].flatMap { $0.isEmpty ? nil : $0 } ?? "zsign",
            zipLevel: try bounded("STOREFRONT_RUNNER_ZIP_LEVEL", default: 1, range: 0...9),
            concurrency: try bounded("STOREFRONT_RUNNER_CONCURRENCY", default: 2, range: 1...4),
            pollInterval: .seconds(Int(env["STOREFRONT_RUNNER_POLL_SECONDS"] ?? "") ?? 10),
            cacheDirectory: env["STOREFRONT_RUNNER_CACHE_ENABLED"] == "0" ? nil : env["STOREFRONT_RUNNER_CACHE_DIR"].map { URL(fileURLWithPath: $0, isDirectory: true) } ?? work.appendingPathComponent(".cache", isDirectory: true),
            cacheMaxBytes: Int64(try bounded("STOREFRONT_RUNNER_CACHE_MAX_BYTES", default: 8 * 1024 * 1024 * 1024, range: 1...(64 * 1024 * 1024 * 1024))),
            cacheTTL: TimeInterval(try bounded("STOREFRONT_RUNNER_CACHE_TTL_SECONDS", default: 86_400, range: 1...604_800))
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
