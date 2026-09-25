import Foundation
import os

/// The lease loop (IMPLEMENTATION_PLAN D9): heartbeat, lease, sign, upload, report.
/// One job at a time; each job folder is wiped when the job ends.
public actor Runner {
    private let config: RunnerConfig
    private let client: WorkerClient
    private let signer: Signer
    private let log = Logger(subsystem: "storefront.runner", category: "runner")
    private var lastHeartbeat = Date.distantPast

    public init(config: RunnerConfig) {
        self.config = config
        self.client = WorkerClient(config: config)
        self.signer = Signer(keychain: config.keychain)
    }

    public func run() async {
        log.info("runner \(self.config.keyID, privacy: .public) starting, version \(self.config.version, privacy: .public)")
        while !Task.isCancelled {
            do {
                try await heartbeatIfDue()
                if let job = try await client.lease() {
                    await process(job)
                    continue
                }
            } catch {
                log.error("loop error: \(String(describing: error), privacy: .public)")
            }
            try? await Task.sleep(for: config.pollInterval)
        }
    }

    private func heartbeatIfDue() async throws {
        guard Date().timeIntervalSince(lastHeartbeat) >= 60 else { return }
        let identities = try Identities.load(keychain: config.keychain)
        try await client.heartbeat(version: config.version, identities: identities)
        lastHeartbeat = Date()
    }

    private func process(_ job: SigningJob) async {
        let folder = config.workDirectory.appendingPathComponent(job.jobID, isDirectory: true)
        log.info("job \(job.jobID, privacy: .public) leased for \(job.bundleIdentifier, privacy: .public)")

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
            let source = folder.appendingPathComponent("source.ipa")
            try await client.downloadSource(job, to: source)
            let output = try signer.sign(job: job, source: source, workDirectory: folder)
            try await client.upload(job, file: output.ipa, sha256: output.sha256)
            try await client.reportSuccess(job, sha256: output.sha256, report: output.report)
            log.info("job \(job.jobID, privacy: .public) signed \(output.sha256, privacy: .public)")
        } catch let RunnerError.job(code, message, _) {
            log.error("job \(job.jobID, privacy: .public) failed: \(code, privacy: .public)")
            try? await client.reportFailure(job, code: code, message: message)
        } catch {
            log.error("job \(job.jobID, privacy: .public) error: \(String(describing: error), privacy: .public)")
            try? await client.reportFailure(job, code: "RUNNER_ERROR", message: String(describing: error))
        }
    }
}
