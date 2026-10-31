import Foundation
import OSLog

/// Uploads from Files that are still running, saved in Application Support so they survive the app
/// being closed or killed: on the next launch `ImportCenter` rebuilds them, asks the server which
/// chunks it already has, and continues.
nonisolated struct PersistedImportUpload: Codable, Equatable, Sendable {
    /// `ImportCenter.Upload.id`.
    var id: UUID
    var name: String
    /// What POST /imports was called with; calling it again with the same values returns the open
    /// upload with the chunks the server has (the backend's resume path).
    var filename: String
    var sizeBytes: Int64
    var sha256: String

    var uploadID: String
    var importID: String
    var uploadToken: String?
    var uploadTokenExpiresAt: String?
    var chunkSize: Int
    var chunkCount: Int
    /// SHA-256 of each chunk computed so far, by chunk number.
    var chunkHashes: [Int: String] = [:]
    /// Chunks the server confirmed.
    var sentChunks: [Int] = []
    /// Chunks were handed to the background session.
    var background = false

    /// The file: a bookmark (a Files pick, which keeps its security scope) and/or a path in our
    /// own container (a copy another app opened in Ru App Store, or one we staged).
    var bookmark: Data?
    var stagedPath: String?
    /// The file is ours (Documents/Inbox) and is deleted once the upload is over.
    var ownsFile = false
}

nonisolated final class ImportUploadStore: @unchecked Sendable {
    static let standard = ImportUploadStore(directory: URL.applicationSupportDirectory.appending(path: "ImportUploads", directoryHint: .isDirectory))

    let directory: URL
    private let lock = NSLock()
    private let log = Logger(subsystem: "storefront", category: "imports")

    init(directory: URL) {
        self.directory = directory
    }

    private var file: URL { directory.appending(path: "uploads.json") }

    func load() -> [PersistedImportUpload] {
        lock.withLock {
            guard let data = try? Data(contentsOf: file) else { return [] }
            do {
                return try JSONDecoder().decode([PersistedImportUpload].self, from: data)
            } catch {
                log.error("import upload records unreadable: \(String(describing: error), privacy: .public)")
                return []
            }
        }
    }

    func save(_ records: [PersistedImportUpload]) {
        lock.withLock {
            do {
                if records.isEmpty {
                    try? FileManager.default.removeItem(at: file)
                    return
                }
                try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
                var folder = directory
                var values = URLResourceValues()
                values.isExcludedFromBackup = true
                try? folder.setResourceValues(values)
                try JSONEncoder().encode(records).write(to: file, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            } catch {
                log.error("import upload records not saved: \(String(describing: error), privacy: .public)")
            }
        }
    }

    func upsert(_ record: PersistedImportUpload) {
        var records = load()
        if let index = records.firstIndex(where: { $0.id == record.id }) {
            records[index] = record
        } else {
            records.append(record)
        }
        save(records)
    }

    func remove(_ id: UUID) {
        let records = load()
        guard records.contains(where: { $0.id == id }) else { return }
        save(records.filter { $0.id != id })
    }

    // MARK: File references

    /// A bookmark for a file outside our container (nil when it is ours, or bookmarks fail).
    static func bookmark(for url: URL) -> Data? {
        try? url.bookmarkData(options: .minimalBookmark, includingResourceValuesForKeys: nil, relativeTo: nil)
    }

    /// The file again after a relaunch: the bookmark (which carries a Files pick's security scope),
    /// else the stored path if the file is still there.
    static func resolve(_ record: PersistedImportUpload) -> URL? {
        if let bookmark = record.bookmark {
            var stale = false
            if let url = try? URL(resolvingBookmarkData: bookmark, options: [], relativeTo: nil, bookmarkDataIsStale: &stale) {
                return url
            }
        }
        if let path = record.stagedPath, FileManager.default.fileExists(atPath: path) {
            return URL(fileURLWithPath: path)
        }
        return nil
    }
}
