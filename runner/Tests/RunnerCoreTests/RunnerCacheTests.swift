import Foundation
import Testing
@testable import RunnerCore

@Suite struct RunnerCacheTests {
    private func job(_ bytes: Data) -> SigningJob {
        SigningJob(jobID: "test", signedBuildID: "build", bundleIdentifier: "com.example.test", teamIdentifier: "TESTTEAM01", certificateSHA1: "cert", profile: .init(uuid: "profile", content: ""), source: .init(sha256: RequestSigner.sha256(bytes), sizeBytes: bytes.count, path: "/source"), uploadPath: "", resultPath: "")
    }

    @Test func coalescesDownloadsAndReusesTheVerifiedSource() async throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: root) }
        let cache = RunnerCache(settings: .init(directory: root.appendingPathComponent("cache")))
        let count = DownloadCount()
        let bytes = Data("source ipa bytes".utf8)
        let job = job(bytes)
        try await withThrowingTaskGroup(of: Void.self) { group in
            for i in 0..<2 {
                group.addTask {
                    _ = try await cache.source(job: job, to: root.appendingPathComponent("job-\(i).ipa")) { url in
                        await count.increment()
                        try await Task.sleep(for: .milliseconds(40))
                        try bytes.write(to: url)
                    }
                }
            }
            try await group.waitForAll()
        }
        let hit = try await cache.source(job: job, to: root.appendingPathComponent("job-3.ipa")) { url in
            await count.increment(); try bytes.write(to: url)
        }
        #expect(hit)
        #expect(await count.value == 1)
        #expect(try Data(contentsOf: root.appendingPathComponent("job-3.ipa")) == bytes)
    }

    @Test func replacesCorruptSourcesAndNeverPublishesWrongDownloads() async throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: root) }
        let settings = RunnerCache.Settings(directory: root.appendingPathComponent("cache"))
        let cache = RunnerCache(settings: settings)
        let bytes = Data("valid source".utf8)
        let job = job(bytes)
        _ = try await cache.source(job: job, to: root.appendingPathComponent("first.ipa")) { try bytes.write(to: $0) }
        let stored = settings.directory.appendingPathComponent("entries/source-\(job.source.sha256)/source.ipa")
        try Data("wrong source".utf8).write(to: stored)
        let hit = try await cache.source(job: job, to: root.appendingPathComponent("second.ipa")) { try bytes.write(to: $0) }
        #expect(!hit)
        #expect(try Data(contentsOf: root.appendingPathComponent("second.ipa")) == bytes)

        try FileManager.default.removeItem(at: stored)
        await #expect(throws: RunnerError.self) {
            _ = try await cache.source(job: job, to: root.appendingPathComponent("bad.ipa")) { try Data("wrong download".utf8).write(to: $0) }
        }
        #expect(!FileManager.default.fileExists(atPath: root.appendingPathComponent("bad.ipa").path))
    }

    @Test func boundsDiskUsageWithoutRemovingPinnedJobSources() async throws {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: root) }
        let settings = RunnerCache.Settings(directory: root.appendingPathComponent("cache"), maxBytes: 6)
        let cache = RunnerCache(settings: settings)
        for i in 0..<3 {
            let bytes = Data(repeating: UInt8(i), count: 4)
            _ = try await cache.source(job: job(bytes), to: root.appendingPathComponent("job-\(i).ipa")) { try bytes.write(to: $0) }
        }
        await cache.prune()
        let entries = try FileManager.default.contentsOfDirectory(atPath: settings.directory.appendingPathComponent("entries").path)
        #expect(entries.count == 1)
        for i in 0..<3 {
            #expect(try Data(contentsOf: root.appendingPathComponent("job-\(i).ipa")) == Data(repeating: UInt8(i), count: 4))
        }
    }

    private actor DownloadCount {
        var value = 0
        func increment() { value += 1 }
    }
}
