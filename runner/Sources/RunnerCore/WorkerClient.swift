import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/// A leased signing job, as SigningService::describe() returns it.
public struct SigningJob: Codable, Sendable {
    public struct Profile: Codable, Sendable {
        public var uuid: String
        /// Base64 .mobileprovision.
        public var content: String

        public init(uuid: String, content: String) {
            self.uuid = uuid
            self.content = content
        }
    }

    public struct Source: Codable, Sendable {
        public var sha256: String
        public var sizeBytes: Int
        public var path: String
        public var downloadURL: URL?
        enum CodingKeys: String, CodingKey { case sha256, sizeBytes = "size_bytes", path, downloadURL = "download_url" }

        public init(sha256: String, sizeBytes: Int, path: String, downloadURL: URL? = nil) {
            self.sha256 = sha256
            self.sizeBytes = sizeBytes
            self.path = path
            self.downloadURL = downloadURL
        }
    }

    /// A dylib the backend asks us to inject into the main app before signing, so a
    /// re-signed app can reach the App Group / keychain it was actually granted
    /// (RuStoreCompat; see ios/compat-shim). zsign copies it into the bundle, adds the
    /// load command and signs it with the leased certificate like any other binary.
    public struct InjectDylib: Codable, Sendable {
        /// Basename inside the app, e.g. "RuStoreCompat.dylib".
        public var name: String
        /// Base64 of the dylib.
        public var content: String
        public var weak: Bool?

        public init(name: String, content: String, weak: Bool? = nil) {
            self.name = name
            self.content = content
            self.weak = weak
        }
    }

    /// An app extension signed with its own profile (one per bundle ID).
    public struct Nested: Codable, Sendable {
        /// Path inside the .app, e.g. "PlugIns/Tunnel.appex".
        public var path: String
        public var bundleIdentifier: String
        public var profile: Profile

        public init(path: String, bundleIdentifier: String, profile: Profile) {
            self.path = path
            self.bundleIdentifier = bundleIdentifier
            self.profile = profile
        }

        enum CodingKeys: String, CodingKey { case path, bundleIdentifier = "bundle_identifier", profile }
    }

    public var jobID: String
    public var signedBuildID: String
    /// The bundle ID the signed app carries; the IPA is re-identified to it when it differs.
    public var bundleIdentifier: String
    public var teamIdentifier: String
    public var certificateSHA1: String
    public var profile: Profile
    public var nested: [Nested]
    public var injectDylibs: [InjectDylib]
    /// Also inject the (shim-only) dylibs into the app's extensions (zsign -P), for a VPN whose
    /// packet-tunnel extension shares its config with the app through an App Group.
    public var injectIntoExtensions: Bool
    /// A one-time, device-bound login code the backend asks us to write into the main app's
    /// Info.plist (StorefrontBootstrapClaim) before signing, so the storefront app can sign the
    /// enrolled customer in on first launch without a password. Only ever set for that app.
    public var bootstrapClaim: String?
    /// The vendor's own bundle ID, for apps whose servers check it (e.g. Yandex sign-in). Written
    /// into Info.plist as RuStoreOriginalBundleIdentifier; the RuStoreCompat shim answers the
    /// app's own bundle-ID lookups with it. Only set for apps the backend lists.
    public var originalBundleIdentifier: String?
    /// The team ID the vendor built the app with, when it differs from ours. Info.plist values that
    /// start with it (e.g. a KeychainAccessGroup the app uses as-is) are re-prefixed with the
    /// signing team's ID, so they fall under its `<TEAM>.*` keychain access.
    public var originalTeamIdentifier: String?
    public var source: Source
    public var uploadPath: String
    public var resultPath: String

    public init(jobID: String, signedBuildID: String, bundleIdentifier: String, teamIdentifier: String, certificateSHA1: String, profile: Profile, nested: [Nested] = [], injectDylibs: [InjectDylib] = [], injectIntoExtensions: Bool = false, bootstrapClaim: String? = nil, originalBundleIdentifier: String? = nil, originalTeamIdentifier: String? = nil, source: Source, uploadPath: String, resultPath: String) {
        self.jobID = jobID
        self.signedBuildID = signedBuildID
        self.bundleIdentifier = bundleIdentifier
        self.teamIdentifier = teamIdentifier
        self.certificateSHA1 = certificateSHA1
        self.profile = profile
        self.nested = nested
        self.injectDylibs = injectDylibs
        self.injectIntoExtensions = injectIntoExtensions
        self.bootstrapClaim = bootstrapClaim
        self.originalBundleIdentifier = originalBundleIdentifier
        self.originalTeamIdentifier = originalTeamIdentifier
        self.source = source
        self.uploadPath = uploadPath
        self.resultPath = resultPath
    }

    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        jobID = try container.decode(String.self, forKey: .jobID)
        signedBuildID = try container.decode(String.self, forKey: .signedBuildID)
        bundleIdentifier = try container.decode(String.self, forKey: .bundleIdentifier)
        teamIdentifier = try container.decode(String.self, forKey: .teamIdentifier)
        certificateSHA1 = try container.decode(String.self, forKey: .certificateSHA1)
        profile = try container.decode(Profile.self, forKey: .profile)
        // Leases from backends that predate extension signing carry no list.
        nested = try container.decodeIfPresent([Nested].self, forKey: .nested) ?? []
        injectDylibs = try container.decodeIfPresent([InjectDylib].self, forKey: .injectDylibs) ?? []
        injectIntoExtensions = try container.decodeIfPresent(Bool.self, forKey: .injectIntoExtensions) ?? false
        bootstrapClaim = try container.decodeIfPresent(String.self, forKey: .bootstrapClaim)
        originalBundleIdentifier = try container.decodeIfPresent(String.self, forKey: .originalBundleIdentifier)
        originalTeamIdentifier = try container.decodeIfPresent(String.self, forKey: .originalTeamIdentifier)
        source = try container.decode(Source.self, forKey: .source)
        uploadPath = try container.decode(String.self, forKey: .uploadPath)
        resultPath = try container.decode(String.self, forKey: .resultPath)
    }

    enum CodingKeys: String, CodingKey {
        case jobID = "job_id", signedBuildID = "signed_build_id", bundleIdentifier = "bundle_identifier"
        case teamIdentifier = "team_identifier", certificateSHA1 = "certificate_sha1", profile, nested, source
        case injectDylibs = "inject_dylibs"
        case injectIntoExtensions = "inject_into_extensions"
        case bootstrapClaim = "bootstrap_claim"
        case originalBundleIdentifier = "original_bundle_identifier"
        case originalTeamIdentifier = "original_team_identifier"
        case uploadPath = "upload_path", resultPath = "result_path"
    }
}

/// HTTPS client for /api/worker/v1 with HMAC-signed requests (IMPLEMENTATION_PLAN D9).
public final class WorkerClient: Sendable {
    private let baseURL: URL
    private let signer: RequestSigner
    private let session: URLSession
    private let configuration: URLSessionConfiguration
    let retry: RetryPolicy

    /// A request that sends or receives nothing for this long fails (and is retried); a whole
    /// transfer of a large IPA may take much longer.
    public static let idleTimeout: TimeInterval = 120
    public static let resourceTimeout: TimeInterval = 3 * 3600

    public static func sessionConfiguration() -> URLSessionConfiguration {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = idleTimeout
        configuration.timeoutIntervalForResource = resourceTimeout
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        configuration.urlCache = nil
        return configuration
    }

    public init(config: RunnerConfig, session: URLSession? = nil, retry: RetryPolicy = .init()) {
        let configuration = session?.configuration ?? Self.sessionConfiguration()
        self.baseURL = config.baseURL
        self.signer = RequestSigner(keyID: config.keyID, secret: config.secret)
        self.configuration = configuration
        self.session = session ?? URLSession(configuration: configuration)
        self.retry = retry
    }

    private struct Envelope<T: Decodable>: Decodable { var data: T? }

    public func heartbeat(version: String, identities: [SigningIdentity]) async throws {
        struct Body: Encodable { var version: String; var identities: [SigningIdentity] }
        _ = try await send("POST", "/api/worker/v1/heartbeat", json: Body(version: version, identities: identities))
    }

    public func lease() async throws -> SigningJob? {
        let data = try await send("POST", "/api/worker/v1/leases", json: [String: String]())
        return try JSONDecoder().decode(Envelope<SigningJob>.self, from: data).data
    }

    public func extendLease(_ job: SigningJob) async throws {
        _ = try await send("POST", "/api/worker/v1/jobs/\(job.jobID)/heartbeat", json: [String: String]())
    }

    public func downloadSource(_ job: SigningJob, to destination: URL) async throws {
        if job.source.downloadURL != nil {
            do {
                let request = try sourceRequest(job.source)
                try await retry.run("source download") { try await self.download({ request }, to: destination) }
                return
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                // A stale presigned link or object-store outage can use the authenticated relay.
                Log.info("direct source download unavailable (\(error)); using worker relay")
            }
        }
        // Each attempt is signed afresh: the backend refuses a reused nonce.
        try await retry.run("relay source download") {
            try await self.download({ self.request("GET", job.source.path, bodyHash: RequestSigner.sha256(Data())) }, to: destination)
        }
    }

    /// Presigned object requests never carry runner authentication headers.
    func sourceRequest(_ source: SigningJob.Source) throws -> URLRequest {
        guard let url = source.downloadURL, url.scheme == "https", url.host != nil,
              url.user == nil, url.password == nil else {
            throw RunnerError.configuration("Source download URL must use HTTPS")
        }
        var request = URLRequest(url: url)
        request.timeoutInterval = Self.idleTimeout
        return request
    }

    /// Streams to `destination`. Bytes already there from a failed attempt are kept and the
    /// rest is asked for with Range; a server that answers 200 sends the whole file again.
    private func download(_ makeRequest: () throws -> URLRequest, to destination: URL) async throws {
        var request = try makeRequest()
        let existing = Signer.size(of: destination)
        if existing > 0 { request.setValue("bytes=\(existing)-", forHTTPHeaderField: "Range") }
        let writer = FileDownload(destination: destination, offset: existing)
        let session = URLSession(configuration: configuration, delegate: writer, delegateQueue: nil)
        defer { session.finishTasksAndInvalidate() }
        let task = session.dataTask(with: request)
        try await withTaskCancellationHandler {
            try await writer.wait(starting: task)
        } onCancel: {
            task.cancel()
        }
        if existing > 0 { Log.info("resumed a download at byte \(existing) (\(writer.resumed ? "range" : "full restart"))") }
    }

    public func upload(_ job: SigningJob, file: URL, sha256: String) async throws {
        try await retry.run("upload") {
            var request = self.request("PUT", job.uploadPath, bodyHash: sha256)
            request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
            let (data, response) = try await self.session.upload(for: request, fromFile: file)
            try self.check(response, body: data)
        }
    }

    public func reportSuccess(_ job: SigningJob, sha256: String, report: [String: String]) async throws {
        struct Body: Encodable { var status = "succeeded"; var sha256: String; var report: [String: String] }
        _ = try await send("POST", job.resultPath, json: Body(sha256: sha256, report: report))
    }

    public func reportFailure(_ job: SigningJob, code: String, message: String, retryable: Bool) async throws {
        _ = try await send("POST", job.resultPath, json: FailureBody(errorCode: code, errorMessage: String(message.prefix(2000)), retryable: retryable))
    }

    struct FailureBody: Encodable {
        var status = "failed"
        var errorCode: String
        var errorMessage: String
        var retryable: Bool
        enum CodingKeys: String, CodingKey { case status, errorCode = "error_code", errorMessage = "error_message", retryable }
    }

    private func send(_ method: String, _ path: String, json body: some Encodable) async throws -> Data {
        let payload = try JSONEncoder().encode(body)
        var request = request(method, path, bodyHash: RequestSigner.sha256(payload))
        request.httpBody = payload
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        let (data, response) = try await session.data(for: request)
        try check(response, body: data)
        return data
    }

    private func request(_ method: String, _ path: String, bodyHash: String) -> URLRequest {
        var request = URLRequest(url: baseURL.appending(path: path))
        request.httpMethod = method
        request.timeoutInterval = Self.idleTimeout
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        for (name, value) in signer.headers(method: method, path: path, contentSHA256: bodyHash) {
            request.setValue(value, forHTTPHeaderField: name)
        }
        return request
    }

    private func check(_ response: URLResponse, body: Data) throws {
        guard let http = response as? HTTPURLResponse else { throw RunnerError.http(status: 0, body: "") }
        guard (200..<300).contains(http.statusCode) else {
            throw RunnerError.http(status: http.statusCode, body: String(decoding: body.prefix(2000), as: UTF8.self))
        }
    }
}

/// Exponential backoff with jitter for transfers: network errors (timeouts, dropped
/// connections) and 5xx/408/429 are retried; anything else fails at once.
public struct RetryPolicy: Sendable {
    public var attempts: Int
    public var baseDelay: Duration
    public var maxDelay: Duration

    public init(attempts: Int = 4, baseDelay: Duration = .seconds(2), maxDelay: Duration = .seconds(30)) {
        self.attempts = attempts
        self.baseDelay = baseDelay
        self.maxDelay = maxDelay
    }

    public static func isTransient(_ error: Error) -> Bool {
        switch error {
        case let RunnerError.http(status, _):
            return status == 0 || status == 408 || status == 429 || (500...599).contains(status)
        case let error as URLError:
            return [.timedOut, .networkConnectionLost, .cannotConnectToHost, .cannotFindHost, .dnsLookupFailed,
                    .notConnectedToInternet, .secureConnectionFailed, .badServerResponse, .resourceUnavailable,
                    .cannotParseResponse, .zeroByteResource].contains(error.code)
        case is TransferError:
            return true
        default:
            let nsError = error as NSError
            return nsError.domain == NSURLErrorDomain && [-1001, -1003, -1004, -1005, -1009].contains(nsError.code)
        }
    }

    /// The delay before attempt `n + 1` (n from 1): base·2^(n-1), capped, plus up to 50% jitter.
    public func delay(after attempt: Int) -> Duration {
        let capped = min(baseDelay * (1 << min(attempt - 1, 16)), maxDelay)
        return capped + capped * Double.random(in: 0...0.5)
    }

    public func run<T: Sendable>(_ what: String, isolation: isolated (any Actor)? = #isolation, isRetryable: (Error) -> Bool = RetryPolicy.isTransient, _ body: () async throws -> T) async throws -> T {
        var attempt = 1
        while true {
            do {
                return try await body()
            } catch {
                guard attempt < attempts, !Task.isCancelled, isRetryable(error) else { throw error }
                let pause = delay(after: attempt)
                Log.info("\(what) attempt \(attempt) failed (\(error)); retrying in \(pause)")
                try await Task.sleep(for: pause)
                attempt += 1
            }
        }
    }
}

struct TransferError: Error, CustomStringConvertible {
    var description: String
}

/// Writes one download's body to a file as it arrives, appending to a partial file when the
/// server answers a Range request with 206.
final class FileDownload: NSObject, URLSessionDataDelegate, @unchecked Sendable {
    private let lock = NSLock()
    private let destination: URL
    private let offset: Int64
    private var handle: FileHandle?
    private var failure: Error?
    private var continuation: CheckedContinuation<Void, Error>?
    private var finished: Result<Void, Error>?
    private var rangeResumed = false

    var resumed: Bool { lock.withLock { rangeResumed } }

    init(destination: URL, offset: Int64) {
        self.destination = destination
        self.offset = offset
        if !FileManager.default.fileExists(atPath: destination.path) {
            FileManager.default.createFile(atPath: destination.path, contents: nil, attributes: [.posixPermissions: 0o600])
        }
    }

    func wait(starting task: URLSessionDataTask) async throws {
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
            lock.withLock {
                if let finished { continuation.resume(with: finished) } else { self.continuation = continuation }
            }
            task.resume()
        }
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive response: URLResponse, completionHandler: @escaping (URLSession.ResponseDisposition) -> Void) {
        do {
            guard let http = response as? HTTPURLResponse else { throw RunnerError.http(status: 0, body: "") }
            switch http.statusCode {
            case 206 where offset > 0 && Self.rangeStart(http) == offset:
                let handle = try FileHandle(forWritingTo: destination)
                try handle.seekToEnd()
                lock.withLock { self.handle = handle; rangeResumed = true }
            case 200..<300 where http.statusCode != 206:
                let handle = try FileHandle(forWritingTo: destination)
                try handle.truncate(atOffset: 0)
                lock.withLock { self.handle = handle }
            case 206, 416:
                // A range we did not ask for, or past the end: drop the partial file, start over.
                try? FileManager.default.removeItem(at: destination)
                FileManager.default.createFile(atPath: destination.path, contents: nil, attributes: [.posixPermissions: 0o600])
                throw TransferError(description: "HTTP \(http.statusCode) for a download resumed at byte \(offset)")
            default:
                throw RunnerError.http(status: http.statusCode, body: "")
            }
            completionHandler(.allow)
        } catch {
            lock.withLock { failure = error }
            completionHandler(.cancel)
        }
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        lock.withLock {
            guard failure == nil, let handle else { return }
            do { try handle.write(contentsOf: data) } catch { failure = error; dataTask.cancel() }
        }
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        lock.withLock {
            try? handle?.close()
            handle = nil
            let result: Result<Void, Error>
            if let failure { result = .failure(failure) } else if let error { result = .failure(error) } else { result = .success(()) }
            if let continuation {
                continuation.resume(with: result)
                self.continuation = nil
            } else {
                finished = result
            }
        }
    }

    /// The first byte of a `Content-Range: bytes START-END/TOTAL` answer.
    static func rangeStart(_ response: HTTPURLResponse) -> Int64? {
        guard let value = response.value(forHTTPHeaderField: "Content-Range"), value.hasPrefix("bytes ") else { return nil }
        return Int64(value.dropFirst(6).prefix { $0.isNumber })
    }
}
