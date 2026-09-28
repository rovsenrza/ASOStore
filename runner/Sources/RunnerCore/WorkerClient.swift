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
        enum CodingKeys: String, CodingKey { case sha256, sizeBytes = "size_bytes", path }

        public init(sha256: String, sizeBytes: Int, path: String) {
            self.sha256 = sha256
            self.sizeBytes = sizeBytes
            self.path = path
        }
    }

    public var jobID: String
    public var signedBuildID: String
    public var bundleIdentifier: String
    public var teamIdentifier: String
    public var certificateSHA1: String
    public var profile: Profile
    public var source: Source
    public var uploadPath: String
    public var resultPath: String

    public init(jobID: String, signedBuildID: String, bundleIdentifier: String, teamIdentifier: String, certificateSHA1: String, profile: Profile, source: Source, uploadPath: String, resultPath: String) {
        self.jobID = jobID
        self.signedBuildID = signedBuildID
        self.bundleIdentifier = bundleIdentifier
        self.teamIdentifier = teamIdentifier
        self.certificateSHA1 = certificateSHA1
        self.profile = profile
        self.source = source
        self.uploadPath = uploadPath
        self.resultPath = resultPath
    }

    enum CodingKeys: String, CodingKey {
        case jobID = "job_id", signedBuildID = "signed_build_id", bundleIdentifier = "bundle_identifier"
        case teamIdentifier = "team_identifier", certificateSHA1 = "certificate_sha1", profile, source
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
        let (temporary, response) = try await session.download(for: request("GET", job.source.path, bodyHash: RequestSigner.sha256(Data())))
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
