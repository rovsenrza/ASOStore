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
    /// A one-time, device-bound login code the backend asks us to write into the main app's
    /// Info.plist (StorefrontBootstrapClaim) before signing, so the storefront app can sign the
    /// enrolled customer in on first launch without a password. Only ever set for that app.
    public var bootstrapClaim: String?
    /// The vendor's own bundle ID, for apps whose servers check it (e.g. Yandex sign-in). Written
    /// into Info.plist as RuStoreOriginalBundleIdentifier; the RuStoreCompat shim answers the
    /// app's own bundle-ID lookups with it. Only set for apps the backend lists.
    public var originalBundleIdentifier: String?
    public var source: Source
    public var uploadPath: String
    public var resultPath: String

    public init(jobID: String, signedBuildID: String, bundleIdentifier: String, teamIdentifier: String, certificateSHA1: String, profile: Profile, nested: [Nested] = [], injectDylibs: [InjectDylib] = [], bootstrapClaim: String? = nil, originalBundleIdentifier: String? = nil, source: Source, uploadPath: String, resultPath: String) {
        self.jobID = jobID
        self.signedBuildID = signedBuildID
        self.bundleIdentifier = bundleIdentifier
        self.teamIdentifier = teamIdentifier
        self.certificateSHA1 = certificateSHA1
        self.profile = profile
        self.nested = nested
        self.injectDylibs = injectDylibs
        self.bootstrapClaim = bootstrapClaim
        self.originalBundleIdentifier = originalBundleIdentifier
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
        bootstrapClaim = try container.decodeIfPresent(String.self, forKey: .bootstrapClaim)
        originalBundleIdentifier = try container.decodeIfPresent(String.self, forKey: .originalBundleIdentifier)
        source = try container.decode(Source.self, forKey: .source)
        uploadPath = try container.decode(String.self, forKey: .uploadPath)
        resultPath = try container.decode(String.self, forKey: .resultPath)
    }

    enum CodingKeys: String, CodingKey {
        case jobID = "job_id", signedBuildID = "signed_build_id", bundleIdentifier = "bundle_identifier"
        case teamIdentifier = "team_identifier", certificateSHA1 = "certificate_sha1", profile, nested, source
        case injectDylibs = "inject_dylibs"
        case bootstrapClaim = "bootstrap_claim"
        case originalBundleIdentifier = "original_bundle_identifier"
        case uploadPath = "upload_path", resultPath = "result_path"
    }
}

/// HTTPS client for /api/worker/v1 with HMAC-signed requests (IMPLEMENTATION_PLAN D9).
public final class WorkerClient: Sendable {
    private let baseURL: URL
    private let signer: RequestSigner
    private let session: URLSession

    public init(config: RunnerConfig, session: URLSession = .shared) {
        self.baseURL = config.baseURL
        self.signer = RequestSigner(keyID: config.keyID, secret: config.secret)
        self.session = session
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
                try await download(sourceRequest(job.source), to: destination)
                return
            } catch {
                // A stale presigned link or object-store outage can use the authenticated relay.
                Log.info("direct source download unavailable; using worker relay")
            }
        }
        try await download(request("GET", job.source.path, bodyHash: RequestSigner.sha256(Data())), to: destination)
    }

    /// Presigned object requests never carry runner authentication headers.
    func sourceRequest(_ source: SigningJob.Source) throws -> URLRequest {
        guard let url = source.downloadURL, url.scheme == "https", url.host != nil,
              url.user == nil, url.password == nil else {
            throw RunnerError.configuration("Source download URL must use HTTPS")
        }
        var request = URLRequest(url: url)
        request.timeoutInterval = 600
        return request
    }

    private func download(_ request: URLRequest, to destination: URL) async throws {
        let (temporary, response) = try await session.download(for: request)
        defer { try? FileManager.default.removeItem(at: temporary) }
        try check(response, body: Data())
        try? FileManager.default.removeItem(at: destination)
        try FileManager.default.moveItem(at: temporary, to: destination)
    }

    public func upload(_ job: SigningJob, file: URL, sha256: String) async throws {
        var request = request("PUT", job.uploadPath, bodyHash: sha256)
        request.setValue("application/octet-stream", forHTTPHeaderField: "Content-Type")
        let (data, response) = try await session.upload(for: request, fromFile: file)
        try check(response, body: data)
    }

    public func reportSuccess(_ job: SigningJob, sha256: String, report: [String: String]) async throws {
        struct Body: Encodable { var status = "succeeded"; var sha256: String; var report: [String: String] }
        _ = try await send("POST", job.resultPath, json: Body(sha256: sha256, report: report))
    }

    public func reportFailure(_ job: SigningJob, code: String, message: String) async throws {
        struct Body: Encodable { var status = "failed"; var errorCode: String; var errorMessage: String
            enum CodingKeys: String, CodingKey { case status, errorCode = "error_code", errorMessage = "error_message" } }
        _ = try await send("POST", job.resultPath, json: Body(errorCode: code, errorMessage: String(message.prefix(2000))))
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
        request.timeoutInterval = 600
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        for (name, value) in signer.headers(method: method, path: path, contentSHA256: bodyHash) {
            request.setValue(value, forHTTPHeaderField: name)
        }
        return request
    }

    private func check(_ response: URLResponse, body: Data) throws {
        guard let http = response as? HTTPURLResponse else { throw RunnerError.http(status: 0, body: "") }
        guard (200..<300).contains(http.statusCode) else {
            throw RunnerError.http(status: http.statusCode, body: String(decoding: body, as: UTF8.self))
        }
    }
}
