import Foundation
#if os(Linux)
import Glibc
#else
import Darwin
#endif

/// Private, bounded caches. Source downloads are coalesced; mutable signing trees
/// have a stable path and an exclusive OS lock for the entire signing operation.
public actor RunnerCache {
    public struct Settings: Sendable {
        public let directory: URL
        public let maxBytes: Int64
        public let ttl: TimeInterval

        public init(directory: URL, maxBytes: Int64 = 8 * 1024 * 1024 * 1024, ttl: TimeInterval = 86_400) {
            self.directory = directory
            self.maxBytes = maxBytes
            self.ttl = ttl
        }
    }

    private let settings: Settings
    private var downloads: [String: Task<CachedSource, Error>] = [:]

    public init(settings: Settings) { self.settings = settings }

    /// Pins the immutable source in the job folder, so eviction cannot remove an active source.
    public func source(job: SigningJob, to destination: URL, download: @escaping @Sendable (URL) async throws -> Void) async throws -> Bool {
        let hash = job.source.sha256.lowercased()
        guard hash.count == 64, hash.allSatisfy({ "0123456789abcdef".contains($0) }) else {
            throw RunnerError.configuration("Source cache requires a SHA-256 hash")
        }
        let task: Task<CachedSource, Error>
        if let pending = downloads[hash] {
            task = pending
        } else {
            let settings = self.settings
            task = Task { try await Self.fetch(job: job, settings: settings, download: download) }
            downloads[hash] = task
        }
        do {
            let source = try await task.value
            // Keep source's lock alive through the link/copy.
            defer { withExtendedLifetime(source) {}; downloads[hash] = nil }
            do {
                try FileManager.default.linkItem(at: source.file, to: destination)
            } catch {
                try FileManager.default.copyItem(at: source.file, to: destination)
            }
            return source.hit
        } catch {
            downloads[hash] = nil
            throw error
        }
    }

    private static func fetch(job: SigningJob, settings: Settings, download: @Sendable (URL) async throws -> Void) async throws -> CachedSource {
        let key = "source-" + job.source.sha256.lowercased()
        let lock = try CacheLock(settings: settings, key: key)
        let entry = settings.directory.appendingPathComponent("entries/" + key, isDirectory: true)
        let file = entry.appendingPathComponent("source.ipa")
        if fresh(file, settings: settings), try RequestSigner.sha256(fileAt: file) == job.source.sha256.lowercased() {
            try FileManager.default.setAttributes([.modificationDate: Date()], ofItemAtPath: file.path)
            return CachedSource(file: file, hit: true, lock: lock)
        }
        try? FileManager.default.removeItem(at: entry)
        try FileManager.default.createDirectory(at: entry, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        let temporary = entry.appendingPathComponent("download-" + UUID().uuidString)
        defer { try? FileManager.default.removeItem(at: temporary) }
        try await download(temporary)
        guard try RequestSigner.sha256(fileAt: temporary) == job.source.sha256.lowercased() else {
            throw RunnerError.job(code: "SOURCE_HASH_MISMATCH", message: "Downloaded source does not match the lease", retryable: true)
        }
        let bytes = try FileManager.default.attributesOfItem(atPath: temporary.path)[.size] as? NSNumber
        guard let bytes, bytes.int64Value <= settings.maxBytes else {
            throw RunnerError.configuration("Source exceeds the runner cache budget")
        }
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: temporary.path)
        try FileManager.default.moveItem(at: temporary, to: file)
        return CachedSource(file: file, hit: false, lock: lock)
    }

    /// Called after a job releases its signing workspace. Locked entries are never evicted.
    public func prune() {
        let root = settings.directory.appendingPathComponent("entries", isDirectory: true)
        guard let entries = try? FileManager.default.contentsOfDirectory(at: root, includingPropertiesForKeys: nil) else { return }
        let rows = entries.map { entry -> (URL, Date, Int64) in
            let marker = entry.lastPathComponent.hasPrefix("source-") ? entry.appendingPathComponent("source.ipa") : entry.appendingPathComponent("ready")
            let date = (try? FileManager.default.attributesOfItem(atPath: marker.path)[.modificationDate] as? Date) ?? .distantPast
            return (entry, date, Self.size(entry))
        }.sorted { $0.1 < $1.1 }
        var used = rows.reduce(Int64(0)) { $0 + $1.2 }
        for (entry, date, size) in rows where date < Date().addingTimeInterval(-settings.ttl) || used > settings.maxBytes {
            guard let lock = try? CacheLock(settings: settings, key: entry.lastPathComponent, nonblocking: true) else { continue }
            withExtendedLifetime(lock) {
                if (try? FileManager.default.removeItem(at: entry)) != nil { used -= size }
            }
        }
    }

    static func workspace(source: URL, job: SigningJob, zsign: String, settings: Settings) throws -> Workspace {
        // Profiles intentionally vary between devices. Source, signing identities and bundle
        // mappings do not: a change to any of them creates a separate signing tree.
        let toolHash = try RequestSigner.sha256(fileAt: URL(fileURLWithPath: try Shell.locate(zsign)))
        let mapping = job.nested.map { "\($0.path)=\($0.bundleIdentifier)" }.sorted()
        let context = ["runner-cache-v1", job.source.sha256.lowercased(), job.teamIdentifier, job.certificateSHA1.uppercased(), job.bundleIdentifier, toolHash] + mapping
        let key = "sign-" + RequestSigner.sha256(try JSONEncoder().encode(context))
        let lock = try CacheLock(settings: settings, key: key)
        let entry = settings.directory.appendingPathComponent("entries/" + key, isDirectory: true)
        let ready = entry.appendingPathComponent("ready")
        let tree = entry.appendingPathComponent("unpacked", isDirectory: true)
        let expected = try? JSONDecoder().decode([String: String].self, from: Data(contentsOf: entry.appendingPathComponent("integrity.json")))
        let hit = fresh(ready, settings: settings) && expected != nil && (try? snapshot(entry)) == expected
        if !hit {
            try? FileManager.default.removeItem(at: entry)
            try FileManager.default.createDirectory(at: entry, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
            do {
                _ = try Signer.unpack(source, into: tree, code: "UNPACK_FAILED")
                guard size(entry) <= settings.maxBytes else { throw RunnerError.configuration("Unpacked app exceeds the runner cache budget") }
            } catch {
                try? FileManager.default.removeItem(at: entry)
                throw error
            }
        }
        let apps = try FileManager.default.contentsOfDirectory(at: tree.appendingPathComponent("Payload"), includingPropertiesForKeys: nil).filter { $0.pathExtension == "app" }
        guard apps.count == 1 else {
            try? FileManager.default.removeItem(at: entry)
            throw RunnerError.job(code: "NO_APP_BUNDLE", message: "Cached source must contain one app", retryable: false)
        }
        return Workspace(app: apps[0], directory: entry, hit: hit, lock: lock)
    }

    private static func fresh(_ file: URL, settings: Settings) -> Bool {
        guard let attributes = try? FileManager.default.attributesOfItem(atPath: file.path),
              let modified = attributes[.modificationDate] as? Date else { return false }
        return modified > Date().addingTimeInterval(-settings.ttl)
    }

    /// Compare the complete last verified tree, including zsign's cache. A changed
    /// resource or binary is rebuilt from the hash-checked source instead of re-signed.
    private static func snapshot(_ entry: URL) throws -> [String: String] {
        guard let files = FileManager.default.enumerator(at: entry, includingPropertiesForKeys: [.isRegularFileKey, .isSymbolicLinkKey]) else {
            throw RunnerError.configuration("Cannot enumerate the signing cache")
        }
        var result: [String: String] = [:]
        for case let file as URL in files {
            let relative = String(file.path.dropFirst(entry.path.count + 1))
            if relative == "ready" || relative == "integrity.json" { continue }
            let values = try file.resourceValues(forKeys: [.isRegularFileKey, .isSymbolicLinkKey])
            if values.isSymbolicLink == true {
                result[relative] = "link:" + (try FileManager.default.destinationOfSymbolicLink(atPath: file.path))
            } else if values.isRegularFile == true {
                result[relative] = try RequestSigner.sha256(fileAt: file)
            }
        }
        return result
    }

    private static func size(_ directory: URL) -> Int64 {
        guard let files = FileManager.default.enumerator(at: directory, includingPropertiesForKeys: [.isRegularFileKey, .fileSizeKey]) else { return 0 }
        return files.compactMap { item -> Int64? in
            guard let file = item as? URL, let values = try? file.resourceValues(forKeys: [.isRegularFileKey, .fileSizeKey]), values.isRegularFile == true else { return nil }
            return Int64(values.fileSize ?? 0)
        }.reduce(0, +)
    }

    final class Workspace {
        let app: URL
        let directory: URL
        let hit: Bool
        private let lock: CacheLock
        private var committed = false

        fileprivate init(app: URL, directory: URL, hit: Bool, lock: CacheLock) {
            self.app = app; self.directory = directory; self.hit = hit; self.lock = lock
        }

        func commit() throws {
            try JSONEncoder().encode(RunnerCache.snapshot(directory)).write(to: directory.appendingPathComponent("integrity.json"), options: .atomic)
            try Data().write(to: directory.appendingPathComponent("ready"), options: .atomic)
            committed = true
        }

        deinit {
            // A failed signing or verification must never become the next job's template.
            if !committed { try? FileManager.default.removeItem(at: directory) }
        }
    }

    private final class CachedSource: @unchecked Sendable {
        let file: URL
        let hit: Bool
        let lock: CacheLock
        init(file: URL, hit: Bool, lock: CacheLock) { self.file = file; self.hit = hit; self.lock = lock }
    }
}

private final class CacheLock: @unchecked Sendable {
    private let descriptor: Int32

    init(settings: RunnerCache.Settings, key: String, nonblocking: Bool = false) throws {
        let root = settings.directory.appendingPathComponent("locks", isDirectory: true)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        descriptor = open(root.appendingPathComponent(key).path, O_CREAT | O_RDWR, 0o600)
        guard descriptor >= 0 else { throw RunnerError.configuration("Cannot open runner cache lock") }
        guard flock(descriptor, LOCK_EX | (nonblocking ? LOCK_NB : 0)) == 0 else {
            close(descriptor)
            throw RunnerError.configuration("Runner cache entry is locked")
        }
    }

    deinit { flock(descriptor, LOCK_UN); close(descriptor) }
}
