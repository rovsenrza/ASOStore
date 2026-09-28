import Foundation

/// The lease loop (IMPLEMENTATION_PLAN D9): heartbeat, lease, sign, upload, report.
/// One job at a time; each job folder is wiped when the job ends.
public actor Runner {
    private let config: RunnerConfig
    private let client: WorkerClient
    private let signer: Signer
    private var lastHeartbeat = Date.distantPast

    public init(config: RunnerConfig) {
        self.config = config
        self.client = WorkerClient(config: config)
        self.signer = Signer(zsign: config.zsign)
    }

    public func run() async {
        Log.info("runner \(config.keyID) starting, version \(config.version)")
        while !Task.isCancelled {
            do {
                try await heartbeatIfDue()
                if let job = try await client.lease() {
                    await process(job)
                    continue
                }
            } catch {
                Log.error("loop error: \(error)")
            }
            try? await Task.sleep(for: config.pollInterval)
        }
    }

    private func heartbeatIfDue() async throws {
        guard Date().timeIntervalSince(lastHeartbeat) >= 60 else { return }
        let identities = try Identities.load(directory: config.identitiesDirectory).map(\.identity)
        try await client.heartbeat(version: config.version, identities: identities)
        lastHeartbeat = Date()
    }

    private func process(_ job: SigningJob) async {
        let folder = config.workDirectory.appendingPathComponent(job.jobID, isDirectory: true)
        Log.info("job \(job.jobID) leased for \(job.bundleIdentifier)")

        // Keep the lease alive while signing large apps.
        let keepAlive = Task { [client] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(120))
                try? await client.extendLease(job)
            }
        }
        defer {
            keepAlive.cancel()
            try? FileManager.default.removeItem(at: folder)
        }

        do {
            try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
            // Read the identities again: a key removed since the last heartbeat must not be used.
            guard let identity = try Identities.load(directory: config.identitiesDirectory).first(where: { $0.identity.sha1 == job.certificateSHA1.uppercased() }) else {
                throw RunnerError.job(code: "CERTIFICATE_NOT_HELD", message: "No key for certificate \(job.certificateSHA1)", retryable: true)
            }
            let source = folder.appendingPathComponent("source.ipa")
            try await client.downloadSource(job, to: source)
            let output = try signer.sign(job: job, identity: identity, source: source, workDirectory: folder)
            try await client.upload(job, file: output.ipa, sha256: output.sha256)
            try await client.reportSuccess(job, sha256: output.sha256, report: output.report)
            Log.info("job \(job.jobID) signed \(output.sha256)")
        } catch let RunnerError.job(code, message, _) {
            Log.error("job \(job.jobID) failed: \(code): \(message)")
            try? await client.reportFailure(job, code: code, message: message)
        } catch {
            Log.error("job \(job.jobID) error: \(error)")
            try? await client.reportFailure(job, code: "RUNNER_ERROR", message: String(describing: error))
        }
    }
}
