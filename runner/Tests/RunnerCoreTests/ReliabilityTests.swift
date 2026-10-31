import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif
import Testing
@testable import RunnerCore

@Suite struct ShellTests {
    // The runner calls openssl several times per heartbeat; a per-run descriptor leak
    // exhausted the 1024-fd limit within a minute on prod.
    @Test func doesNotLeakDescriptors() throws {
        func openDescriptors() -> Int {
            (try? FileManager.default.contentsOfDirectory(atPath: "/dev/fd").count) ?? 0
        }
        _ = try Shell.run("true", [])
        let before = openDescriptors()
        for _ in 0..<300 { _ = try Shell.run("true", []) }
        #expect(openDescriptors() - before < 20)
    }

    @Test func stopsAToolThatOverrunsItsDeadline() throws {
        let started = Date()
        #expect {
            try Shell.run("sleep", ["30"], timeout: 1)
        } throws: { error in
            guard case let RunnerError.job(code, _, retryable) = error else { return false }
            return code == "TIMEOUT" && retryable
        }
        #expect(Date().timeIntervalSince(started) < 15)
    }

    @Test func killsAToolThatIgnoresSIGTERM() throws {
        let started = Date()
        #expect(throws: RunnerError.self) {
            try Shell.run("sh", ["-c", "trap '' TERM; sleep 60 & wait; sleep 60"], timeout: 1)
        }
        #expect(Date().timeIntervalSince(started) < 25)
    }

    @Test func stopsAToolWhenTheJobIsCancelled() async throws {
        let cancellation = Cancellation()
        let run = Task { try await Blocking.run(cancellation: cancellation) { try Shell.run("sleep", ["30"]) } }
        try await Task.sleep(for: .milliseconds(300))
        cancellation.cancel()
        await #expect {
            _ = try await run.value
        } throws: { error in
            guard case let RunnerError.job(code, _, _) = error else { return false }
            return code == "CANCELLED"
        }
    }

    /// Many more concurrent tools than cores, from async code: this deadlocked when the
    /// stderr reader was a GCD block waited on from cooperative threads.
    @Test func manyConcurrentToolsDoNotStarveEachOther() async throws {
        let count = ProcessInfo.processInfo.activeProcessorCount * 4
        let outputs = try await withThrowingTaskGroup(of: String.self) { group in
            for i in 0..<count {
                group.addTask {
                    try await Blocking.run {
                        try Shell.require("TEST", "sh", ["-c", "echo out-\(i); echo err-\(i) >&2; sleep 0.2"]).output
                    }
                }
            }
            return try await group.reduce(into: [String]()) { $0.append($1) }
        }
        #expect(outputs.count == count)
        #expect(Set(outputs).count == count)
    }

    @Test func boundsAndScalesDeadlines() {
        #expect(Shell.timeout(forBytes: 0) == 120)
        #expect(Shell.timeout(forBytes: 2_000_000_000) == 240)
        #expect(Shell.timeout(forBytes: 500_000_000_000) == 1800)
        #expect(Shell.truncated(String(repeating: "x", count: 5000)).count == 1500)
    }

    @Test func aFailureKeepsOnlyTheEndOfALongMessage() {
        #expect {
            try Shell.require("UNPACK_FAILED", "sh", ["-c", "i=0; while [ $i -lt 3000 ]; do echo line-$i >&2; i=$((i+1)); done; exit 3"])
        } throws: { error in
            guard case let RunnerError.job(code, message, retryable) = error else { return false }
            return code == "UNPACK_FAILED" && !retryable && message.count <= 1500 && message.hasSuffix("line-2999")
        }
    }
}

@Suite struct FailureReportTests {
    @Test func hidesRunnerFoldersAndBoundsTheMessage() {
        let message = "unzip: cannot find /var/lib/storefront-runner/work/job_1/source.ipa or /var/lib/storefront-runner/work/.cache/entries/x"
        let clean = Runner.sanitize(message, paths: ["/var/lib/storefront-runner/work", "/var/lib/storefront-runner/work/.cache"])
        #expect(clean == "unzip: cannot find <work>/job_1/source.ipa or <cache>/entries/x")
        #expect(Runner.sanitize(String(repeating: "y", count: 4000), paths: []).count == 1000)
    }

    @Test func sendsWhetherARetryCanHelp() throws {
        let body = WorkerClient.FailureBody(errorCode: "NESTED_PROFILE_REQUIRED", errorMessage: "Watch/", retryable: false)
        let json = try #require(try JSONSerialization.jsonObject(with: JSONEncoder().encode(body)) as? [String: Any])
        #expect(json["retryable"] as? Bool == false)
        #expect(json["status"] as? String == "failed")
        #expect(json["error_code"] as? String == "NESTED_PROFILE_REQUIRED")
        #expect(Runner.permanentCodes.isSuperset(of: ["NESTED_PROFILE_REQUIRED", "CODESIGN_FAILED", "REPACK_FAILED"]))
    }
}

@Suite struct RetryPolicyTests {
    @Test func retriesOnlyTransientErrors() {
        #expect(RetryPolicy.isTransient(URLError(.timedOut)))
        #expect(RetryPolicy.isTransient(URLError(.networkConnectionLost)))
        #expect(RetryPolicy.isTransient(RunnerError.http(status: 503, body: "")))
        #expect(RetryPolicy.isTransient(RunnerError.http(status: 429, body: "")))
        #expect(!RetryPolicy.isTransient(RunnerError.http(status: 403, body: "")))
        #expect(!RetryPolicy.isTransient(RunnerError.http(status: 409, body: "")))
        #expect(!RetryPolicy.isTransient(RunnerError.job(code: "X", message: "", retryable: true)))
    }

    @Test func backsOffAndGivesUpAfterTheLastAttempt() async throws {
        let policy = RetryPolicy(attempts: 3, baseDelay: .milliseconds(1), maxDelay: .milliseconds(4))
        let counter = Counter()
        await #expect(throws: RunnerError.self) {
            try await policy.run("test") {
                await counter.increment()
                throw RunnerError.http(status: 502, body: "")
            }
        }
        #expect(await counter.value == 3)

        let permanent = Counter()
        await #expect(throws: RunnerError.self) {
            try await policy.run("test") {
                await permanent.increment()
                throw RunnerError.http(status: 404, body: "")
            }
        }
        #expect(await permanent.value == 1)
        #expect(policy.delay(after: 10) <= .milliseconds(6))
    }

    @Test func readsTheResumeOffset() throws {
        let response = try #require(HTTPURLResponse(url: URL(string: "https://x.test")!, statusCode: 206, httpVersion: nil, headerFields: ["Content-Range": "bytes 1024-2047/2048"]))
        #expect(FileDownload.rangeStart(response) == 1024)
    }

    private actor Counter {
        var value = 0
        func increment() { value += 1 }
    }
}

@Suite struct CacheHousekeepingTests {
    private func root() throws -> URL {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent("housekeeping-\(UUID().uuidString)", isDirectory: true)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        return root
    }

    @Test func removesLeftoverJobFoldersButKeepsTheCache() throws {
        let work = try root()
        defer { try? FileManager.default.removeItem(at: work) }
        for name in [".cache", "job_a", "job_b"] {
            try FileManager.default.createDirectory(at: work.appendingPathComponent(name), withIntermediateDirectories: true)
        }
        try Data("x".utf8).write(to: work.appendingPathComponent("stray.ipa"))
        #expect(RunnerCache.sweepWorkDirectory(work, keeping: []) == 3)
        #expect(try FileManager.default.contentsOfDirectory(atPath: work.path) == [".cache"])
    }

    @Test func prunesOnlyUnheldLocksWithoutAnEntry() throws {
        let directory = try root()
        defer { try? FileManager.default.removeItem(at: directory) }
        let settings = RunnerCache.Settings(directory: directory)
        let locks = directory.appendingPathComponent("locks")
        try FileManager.default.createDirectory(at: directory.appendingPathComponent("entries/sign-kept"), withIntermediateDirectories: true)
        for name in ["sign-kept", "sign-gone", "source-gone", "sign-held"] {
            _ = try CacheLock.tryAcquire(settings: settings, key: name)
        }
        let held = try #require(try CacheLock.tryAcquire(settings: settings, key: "sign-held"))
        #expect(RunnerCache.pruneLocks(settings: settings) == 0, "young locks are kept")
        #expect(RunnerCache.pruneLocks(settings: settings, minimumAge: 0) == 2)
        #expect(Set(try FileManager.default.contentsOfDirectory(atPath: locks.path)) == ["sign-kept", "sign-held"])
        // Held locks still exclude, and a pruned name can be locked again.
        #expect(try CacheLock.tryAcquire(settings: settings, key: "sign-held") == nil)
        withExtendedLifetime(held) {}
        #expect(try CacheLock.tryAcquire(settings: settings, key: "sign-gone") != nil)
    }
}

@Suite struct SymlinkTests {
    private func archive(_ build: (URL) throws -> Void) throws -> (URL, URL) {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent("links-\(UUID().uuidString)", isDirectory: true)
        let app = root.appendingPathComponent("src/Payload/Demo.app", isDirectory: true)
        try FileManager.default.createDirectory(at: app, withIntermediateDirectories: true)
        try Data("binary".utf8).write(to: app.appendingPathComponent("Demo"))
        try build(app)
        let ipa = root.appendingPathComponent("demo.ipa")
        try Shell.require("TEST_SETUP", "zip", ["-qry", ipa.path, "Payload"], in: root.appendingPathComponent("src"))
        return (root, ipa)
    }

    @Test(.enabled(if: Tools.available("zip") && Tools.available("unzip")), arguments: ["/etc/passwd", "../../../outside", "Frameworks/../../Other.app"])
    func refusesALinkOutOfTheApp(_ target: String) throws {
        let (root, ipa) = try archive { app in
            try FileManager.default.createSymbolicLink(atPath: app.appendingPathComponent("escape").path, withDestinationPath: target)
        }
        defer { try? FileManager.default.removeItem(at: root) }
        // Debian's unzip (the runner image) extracts the link and the walk refuses it; Apple's
        // unzip refuses to write such a link itself. Either way the job fails permanently.
        #expect {
            try Signer.unpack(ipa, into: root.appendingPathComponent("out"), code: "UNPACK_FAILED")
        } throws: { error in
            guard case let RunnerError.job(code, message, retryable) = error else { return false }
            return !retryable && ((code == "UNSAFE_SYMLINK" && message.contains("escape") && !message.contains(root.path))
                || code == "UNPACK_FAILED")
        }
        // The walk itself, on the tree as Debian's unzip leaves it.
        let source = root.appendingPathComponent("src")
        #expect {
            try Signer.checkSymlinks(in: source, app: source.appendingPathComponent("Payload/Demo.app"))
        } throws: { error in
            guard case let RunnerError.job(code, message, retryable) = error else { return false }
            return code == "UNSAFE_SYMLINK" && !retryable && message == "Payload/Demo.app/escape links outside the app"
        }
    }

    @Test(.enabled(if: Tools.available("zip") && Tools.available("unzip")))
    func allowsLinksInsideTheApp() throws {
        let (root, ipa) = try archive { app in
            let versions = app.appendingPathComponent("Frameworks/Kit.framework/Versions", isDirectory: true)
            try FileManager.default.createDirectory(at: versions.appendingPathComponent("A"), withIntermediateDirectories: true)
            try FileManager.default.createSymbolicLink(atPath: versions.appendingPathComponent("Current").path, withDestinationPath: "A")
            try FileManager.default.createSymbolicLink(atPath: app.appendingPathComponent("Alias").path, withDestinationPath: "Frameworks/Kit.framework/Versions/Current")
        }
        defer { try? FileManager.default.removeItem(at: root) }
        let app = try Signer.unpack(ipa, into: root.appendingPathComponent("out"), code: "UNPACK_FAILED")
        #expect(app.lastPathComponent == "Demo.app")
    }
}

@Suite(.enabled(if: Tools.available("zsign") && Tools.available("openssl") && Tools.available("unzip")))
struct WarmTreeClaimTests {
    private func claim(in ipa: URL, work: URL, _ name: String) throws -> String? {
        let app = try Signer.unpack(ipa, into: work.appendingPathComponent(name), code: "TEST_SETUP")
        let info = try PropertyListSerialization.propertyList(from: Data(contentsOf: app.appendingPathComponent("Info.plist")), format: nil) as? [String: Any]
        return info?["StorefrontBootstrapClaim"] as? String
    }

    /// The claim is not part of the cache key: customers share one signing tree, each build
    /// carries (and is signed over) only its own claim, and a build without one carries none.
    @Test func eachBuildCarriesOnlyItsOwnClaimFromASharedTree() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let signer = Signer(cache: .init(directory: setup.folder.root.appendingPathComponent("cache")))
        var results: [(Signer.Output, URL)] = []
        for (index, claim) in ["claim-customer-a", "claim-customer-b", nil].enumerated() {
            var job = setup.job
            job.bootstrapClaim = claim
            let work = setup.folder.root.appendingPathComponent("claim-job-\(index)")
            try FileManager.default.createDirectory(at: work, withIntermediateDirectories: true)
            results.append((try signer.sign(job: job, identity: setup.identity, source: setup.source, workDirectory: work), work))
        }
        #expect(results.map { $0.0.report["workspace_cache"] } == ["miss", "hit", "hit"])
        #expect(try claim(in: results[0].0.ipa, work: results[0].1, "a") == "claim-customer-a")
        #expect(try claim(in: results[1].0.ipa, work: results[1].1, "b") == "claim-customer-b")
        #expect(try claim(in: results[2].0.ipa, work: results[2].1, "none") == nil)
        // Each signature covers its own Info.plist (Signer.verify ran inside sign; check again).
        for (output, work) in results {
            let app = try Signer.unpack(output.ipa, into: work.appendingPathComponent("verify"), code: "TEST_SETUP")
            _ = try Signer.verify(app: app, job: setup.job, profile: setup.profile, workDirectory: work)
        }
    }

    @Test func signsFromAsyncCodeWithoutBlockingThePool() async throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let output = try await Signer().signDetached(job: setup.job, identity: setup.identity, source: setup.source, workDirectory: setup.work)
        #expect(output.report["codesign"]?.contains("TeamIdentifier=TESTTEAM01") == true)
    }
}
