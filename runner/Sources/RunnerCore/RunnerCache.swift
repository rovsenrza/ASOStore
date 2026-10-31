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
        let lock = try await CacheLock.acquire(settings: settings, key: key)
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

    private var lastLockSweep = Date.distantPast

    /// Called after a job releases its signing workspace. Locked entries are never evicted.
    public func prune() {
        if Date().timeIntervalSince(lastLockSweep) > 600 {
            lastLockSweep = Date()
            let removed = Self.pruneLocks(settings: settings)
            if removed > 0 { Log.info("removed \(removed) stale cache lock files") }
        }
        let root = settings.directory.appendingPathComponent("entries", isDirectory: true)
        guard let entries = try? FileManager.default.contentsOfDirectory(at: root, includingPropertiesForKeys: nil) else { return }
        let rows = entries.map { entry -> (URL, Date, Int64) in
            let marker = entry.lastPathComponent.hasPrefix("source-") ? entry.appendingPathComponent("source.ipa") : entry.appendingPathComponent("ready")
            let date = (try? FileManager.default.attributesOfItem(atPath: marker.path)[.modificationDate] as? Date) ?? .distantPast
            return (entry, date, Self.size(entry))
        }.sorted { $0.1 < $1.1 }
        var used = rows.reduce(Int64(0)) { $0 + $1.2 }
        for (entry, date, size) in rows where date < Date().addingTimeInterval(-settings.ttl) || used > settings.maxBytes {
            guard let lock = try? CacheLock.tryAcquire(settings: settings, key: entry.lastPathComponent) else { continue }
            withExtendedLifetime(lock) {
                if (try? FileManager.default.removeItem(at: entry)) != nil { used -= size }
            }
        }
    }

    /// Removes lock files whose cache entry is gone (one is left behind for every evicted or
    /// failed entry). A lock that is held, or younger than `minimumAge`, is kept. CacheLock
    /// re-checks the path after locking, so removing a file another worker has just opened
    /// cannot give two workers the same entry.
    @discardableResult
    public static func pruneLocks(settings: Settings, minimumAge: TimeInterval = 600) -> Int {
        let locks = settings.directory.appendingPathComponent("locks", isDirectory: true)
        let entries = settings.directory.appendingPathComponent("entries", isDirectory: true)
        guard let names = try? FileManager.default.contentsOfDirectory(atPath: locks.path) else { return 0 }
        var removed = 0
        for name in names where !FileManager.default.fileExists(atPath: entries.appendingPathComponent(name).path) {
            let file = locks.appendingPathComponent(name)
            if minimumAge > 0, let modified = (try? FileManager.default.attributesOfItem(atPath: file.path))?[.modificationDate] as? Date,
               modified > Date().addingTimeInterval(-minimumAge) { continue }
            guard let lock = try? CacheLock.tryAcquire(settings: settings, key: name) else { continue }
            // Still no entry now that we hold the lock: nobody can be filling it.
            if !FileManager.default.fileExists(atPath: entries.appendingPathComponent(name).path), unlink(file.path) == 0 {
                removed += 1
            }
            withExtendedLifetime(lock) {}
        }
        return removed
    }

    /// Start-up: every folder in the work directory belongs to a job of a previous run (a crash
    /// or a killed container skips the per-job clean-up). Keeps the cache.
    @discardableResult
    public static func sweepWorkDirectory(_ work: URL, keeping kept: [URL]) -> Int {
        let keep = Set(kept.map { $0.standardizedFileURL.path })
        guard let children = try? FileManager.default.contentsOfDirectory(at: work, includingPropertiesForKeys: nil) else { return 0 }
        var removed = 0
        for child in children where child.lastPathComponent != ".cache" && !keep.contains(child.standardizedFileURL.path) {
            if (try? FileManager.default.removeItem(at: child)) != nil { removed += 1 }
        }
        return removed
    }

    static func workspace(source: URL, job: SigningJob, zsign: String, injectKey: [String] = [], settings: Settings) throws -> Workspace {
        // Profiles intentionally vary between devices. Source, signing identities, bundle
        // mappings and injected dylibs do not: a change to any of them creates a separate
        // signing tree, so an injected tree is never reused for a plain sign or a stale shim.
        let toolHash = try RequestSigner.sha256(fileAt: URL(fileURLWithPath: try Shell.locate(zsign)))
        let mapping = job.nested.map { "\($0.path)=\($0.bundleIdentifier)" }.sorted()
        let context = ["runner-cache-v1", job.source.sha256.lowercased(), job.teamIdentifier, job.certificateSHA1.uppercased(), job.bundleIdentifier, toolHash] + mapping + ["inject:"] + injectKey.sorted()
        let key = "sign-" + RequestSigner.sha256(try JSONEncoder().encode(context))
        let lock = try CacheLock.acquireBlocking(settings: settings, key: key)
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
    private static func snapshot(_ folder: URL) throws -> [String: String] {
        // Resolve symlinks in the folder's own path first: on macOS the enumerator reports
        // /var/... as /private/var/..., which would shift every relative path below.
        // (Foundation's resolvingSymlinksInPath strips /private again, so use realpath.)
        let entry = realpath(folder.path, nil).map { pointer in
            defer { free(pointer) }
            return URL(fileURLWithPath: String(cString: pointer), isDirectory: true)
        } ?? folder
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

final class CacheLock: @unchecked Sendable {
    private let descriptor: Int32

    private init(descriptor: Int32) { self.descriptor = descriptor }

    /// One non-blocking attempt; nil while another worker holds the lock.
    static func tryAcquire(settings: RunnerCache.Settings, key: String) throws -> CacheLock? {
        let root = settings.directory.appendingPathComponent("locks", isDirectory: true)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        let path = root.appendingPathComponent(key).path
        let descriptor = open(path, O_CREAT | O_RDWR | O_CLOEXEC, 0o600)
        guard descriptor >= 0 else { throw RunnerError.configuration("Cannot open runner cache lock") }
        guard flock(descriptor, LOCK_EX | LOCK_NB) == 0 else {
            close(descriptor)
            return nil
        }
        // The lock file may have been removed (pruneLocks) between open and flock: a lock on
        // an unlinked file excludes nobody, so start again with the file now at that path.
        var held = stat(), current = stat()
        guard fstat(descriptor, &held) == 0, stat(path, &current) == 0,
              held.st_ino == current.st_ino, held.st_dev == current.st_dev else {
            flock(descriptor, LOCK_UN)
            close(descriptor)
            return try tryAcquire(settings: settings, key: key)
        }
        return CacheLock(descriptor: descriptor)
    }

    /// Waits for the lock without blocking a cooperative thread: polls with backoff.
    static func acquire(settings: RunnerCache.Settings, key: String) async throws -> CacheLock {
        var pause = 20
        while true {
            if let lock = try tryAcquire(settings: settings, key: key) { return lock }
            try await Task.sleep(for: .milliseconds(pause + Int.random(in: 0...pause / 2)))
            pause = min(pause * 2, 1000)
        }
    }

    /// For code already on a dedicated thread (signing): polls with backoff, stops when the job
    /// is cancelled or after `timeout` (a signing tree is held for at most one zsign run).
    static func acquireBlocking(settings: RunnerCache.Settings, key: String, timeout: TimeInterval = 2400) throws -> CacheLock {
        let started = Date()
        var pause: useconds_t = 20_000
        while true {
            if let lock = try tryAcquire(settings: settings, key: key) { return lock }
            try Cancellation.current?.check()
            guard Date().timeIntervalSince(started) < timeout else {
                throw RunnerError.job(code: "TIMEOUT", message: "Signing cache entry stayed locked for \(Int(timeout)) s", retryable: true)
            }
            usleep(pause + useconds_t.random(in: 0...pause / 2))
            pause = min(pause * 2, 1_000_000)
        }
    }

    deinit { flock(descriptor, LOCK_UN); close(descriptor) }
}
