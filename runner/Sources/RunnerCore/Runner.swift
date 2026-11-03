import Foundation

/// The lease loop (IMPLEMENTATION_PLAN D9): heartbeat, lease, sign, upload, report.
/// Each actor handles one job at a time; each job folder is wiped when the job ends.
public actor Runner {
    private let config: RunnerConfig
    private let client: WorkerClient
    private let signer: Signer
    private let cache: RunnerCache?
    private var lastHeartbeat = Date.distantPast

    /// Codes a retry cannot fix; the backend fails the build at once (`retryable: false`).
    /// Every other code is reported as retryable, which keeps the backend's attempt budget.
    public static let permanentCodes: Set<String> = ["NESTED_PROFILE_REQUIRED", "CODESIGN_FAILED", "REPACK_FAILED", "UNSAFE_SYMLINK"]

    public init(config: RunnerConfig, cache: RunnerCache? = nil) {
        self.config = config
        self.client = WorkerClient(config: config)
        self.signer = Signer(zsign: config.zsign, zipLevel: config.zipLevel, cache: config.cacheSettings)
        self.cache = cache
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
        let identities = try await loadIdentities().map(\.identity)
        try await client.heartbeat(version: config.version, identities: identities)
        lastHeartbeat = Date()
    }

    /// Identities.load runs openssl: off the cooperative pool.
    private func loadIdentities() async throws -> [IdentityFiles] {
        let directory = config.identitiesDirectory
        return try await Blocking.run { try Identities.load(directory: directory) }
    }

    private struct Signed: Sendable {
        var sha256: String
        /// "delta-v1" / "delta-v2" when the upload was a pack; nil for a full IPA.
        var format: String?
        var report: [String: String]
    }

    private func process(_ job: SigningJob) async {
        let folder = config.workDirectory.appendingPathComponent(job.jobID, isDirectory: true)
        Log.info("job \(job.jobID) leased for \(job.bundleIdentifier)")
        let cancellation = Cancellation()

        let work = Task { try await self.execute(job, folder: folder, cancellation: cancellation) }
        // Keep the lease alive while signing large apps. 404/409 mean the lease is gone (expired
        // and handed to another runner, or the job was cancelled): stop working on it.
        let keepAlive = Task { [client] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(120))
                if Task.isCancelled { return }
                do {
                    try await client.extendLease(job)
                } catch let RunnerError.http(status, _) where status == 404 || status == 409 {
                    Log.error("job \(job.jobID) lease lost (HTTP \(status)); cancelling")
                    cancellation.cancel()
                    work.cancel()
                    return
                } catch {
                    Log.error("job \(job.jobID) lease extension failed: \(error)")
                }
            }
        }
        let result = await work.result
        keepAlive.cancel()
        try? FileManager.default.removeItem(at: folder)

        if cancellation.isCancelled {
            Log.error("job \(job.jobID) abandoned: the lease was lost")
        } else {
            switch result {
            case .success(let signed):
                await reportSuccess(job, signed)
            case .failure(let RunnerError.job(code, message, retryable)):
                await reportFailure(job, code: code, message: message, retryable: retryable)
            case .failure(let error):
                await reportFailure(job, code: "RUNNER_ERROR", message: String(describing: error), retryable: true)
            }
        }
        await cache?.prune()
    }

    private func execute(_ job: SigningJob, folder: URL, cancellation: Cancellation) async throws -> Signed {
        try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        // Read the identities again: a key removed since the last heartbeat must not be used.
        guard let identity = try await loadIdentities().first(where: { $0.identity.sha1 == job.certificateSHA1.uppercased() }) else {
            throw RunnerError.job(code: "CERTIFICATE_NOT_HELD", message: "No key for certificate \(job.certificateSHA1)", retryable: true)
        }
        let source = folder.appendingPathComponent("source.ipa")
        let downloadStarted = Date()
        var sourceCache = "disabled"
        if let cache {
            do {
                let client = self.client
                let hit = try await cache.source(job: job, to: source) { try await client.downloadSource(job, to: $0) }
                sourceCache = hit ? "hit" : "miss"
            } catch let error as RunnerError {
                if case .job = error { throw error }
                try? FileManager.default.removeItem(at: source)
                try await client.downloadSource(job, to: source)
                sourceCache = "unavailable"
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                try? FileManager.default.removeItem(at: source)
                try await client.downloadSource(job, to: source)
                sourceCache = "unavailable"
            }
        } else {
            try await client.downloadSource(job, to: source)
        }
        let downloadSeconds = Date().timeIntervalSince(downloadStarted)
        try Task.checkCancellation()
        // Delta builds: the pack is cut from the signed folder (no IPA is written); any trouble there
        // ends in the full IPA, built the usual way.
        var output = job.delta.enabled
            ? try await signer.signDeltaDetached(job: job, identity: identity, source: source, workDirectory: folder, cancellation: cancellation)
            : try await signer.signDetached(job: job, identity: identity, source: source, workDirectory: folder, cancellation: cancellation)
        try Task.checkCancellation()
        let uploadStarted = Date()
        if output.format == DeltaPack.formatV2 {
            // Delta v2: a new core goes up first, then the pack. A failure of either (after the
            // transfer retries) falls back to the v1 pack from the same tree, then to the full IPA.
            do {
                if let core = output.core {
                    try await client.uploadCore(job, core: core)
                    Log.info("job \(job.jobID) core \(core.sha256) uploaded (\(core.size) bytes)")
                }
                try await client.upload(job, file: output.ipa, sha256: output.sha256, format: output.format)
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                try Task.checkCancellation()
                let reason = "upload: \(error)"
                Log.error("job \(job.jobID) delta-v2 upload failed (\(error)); falling back")
                output = try await signer.fallbackFromDeltaV2Detached(output, reason: reason, job: job, identity: identity, source: source, workDirectory: folder, cancellation: cancellation)
                try Task.checkCancellation()
                try await client.upload(job, file: output.ipa, sha256: output.sha256, format: output.format)
            }
        } else {
            try await client.upload(job, file: output.ipa, sha256: output.sha256, format: output.format)
        }
        var report = output.report
        report["download_seconds"] = String(format: "%.1f", downloadSeconds)
        report["source_cache"] = sourceCache
        report["upload_seconds"] = String(format: "%.1f", Date().timeIntervalSince(uploadStarted))
        return Signed(sha256: output.sha256, format: output.format, report: report)
    }

    /// The build is uploaded: never fall back to a failure report (that would sign it again).
    /// 409 means the backend has already taken a result for this lease.
    private func reportSuccess(_ job: SigningJob, _ signed: Signed) async {
        do {
            try await RetryPolicy(attempts: 5).run("success report for job \(job.jobID)") {
                do {
                    try await self.client.reportSuccess(job, sha256: signed.sha256, report: signed.report, format: signed.format)
                } catch RunnerError.http(409, _) {
                    Log.info("job \(job.jobID) result was already recorded")
                }
            }
            Log.info("job \(job.jobID) signed \(signed.sha256)")
        } catch {
            Log.error("job \(job.jobID) signed \(signed.sha256) but the result was not accepted: \(error); the lease will expire")
        }
    }

    private func reportFailure(_ job: SigningJob, code: String, message: String, retryable: Bool) async {
        let clean = Self.sanitize(message, paths: [config.workDirectory.path] + (config.cacheDirectory.map { [$0.path] } ?? []))
        let sendRetryable = Self.permanentCodes.contains(code) ? retryable : true
        Log.error("job \(job.jobID) failed: \(code) (retryable: \(sendRetryable)): \(clean)")
        do {
            try await RetryPolicy(attempts: 3).run("failure report for job \(job.jobID)") {
                try await self.client.reportFailure(job, code: code, message: clean, retryable: sendRetryable)
            }
        } catch {
            Log.error("job \(job.jobID) failure report was not accepted: \(error)")
        }
    }

    /// A failure message for the backend: runner folders become `<work>`/`<cache>` (they say
    /// nothing useful there and reveal the host's layout), and the length is bounded.
    public static func sanitize(_ message: String, paths: [String], limit: Int = 1000) -> String {
        var text = message
        for (index, path) in paths.enumerated().sorted(by: { $0.element.count > $1.element.count }) where !path.isEmpty {
            let placeholder = index == 0 ? "<work>" : "<cache>"
            var variants = [path]
            if path.hasPrefix("/var/") { variants.append("/private" + path) }
            for variant in variants.sorted(by: { $0.count > $1.count }) {
                text = text.replacingOccurrences(of: variant, with: placeholder)
            }
        }
        return Shell.truncated(text, limit: limit)
    }
}
