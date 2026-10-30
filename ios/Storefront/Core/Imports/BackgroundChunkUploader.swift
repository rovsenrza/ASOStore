import Foundation
import OSLog

/// Sends import chunks through an iOS background URLSession, so an upload keeps going after the
/// customer leaves the app (to install another app, say) or iOS suspends or closes it. Chunks are
/// handed over as files (Application Support/ImportChunks/<upload>/<n>.part; not Caches, which iOS
/// may purge before they are sent); `nsurlsessiond` sends them and wakes the app when they are done. The server authenticates them with the upload's own token
/// and completes the upload itself when the last chunk lands.
nonisolated final class BackgroundChunkUploader: NSObject, URLSessionDataDelegate, @unchecked Sendable {
    enum Outcome: Sendable {
        case stored
        /// `transient`: worth sending again (dropped connection, busy server).
        case failed(transient: Bool, status: Int)
    }

    static let shared = BackgroundChunkUploader()

    private static let identifier = "storefront.import-chunks"
    private let log = Logger(subsystem: "storefront", category: "imports")
    private let lock = NSLock()
    private var handler: (@Sendable (_ uploadID: String, _ number: Int, _ outcome: Outcome) -> Void)?
    private var eventsCompletion: (() -> Void)?

    private var storedSession: URLSession?

    private var session: URLSession {
        lock.withLock {
            if let storedSession {
                return storedSession
            }
            let configuration = URLSessionConfiguration.background(withIdentifier: Self.identifier)
            configuration.isDiscretionary = false
            configuration.sessionSendsLaunchEvents = true
            configuration.allowsCellularAccess = true
            configuration.httpMaximumConnectionsPerHost = 4
            configuration.timeoutIntervalForResource = 24 * 60 * 60
            let created = URLSession(configuration: configuration, delegate: self, delegateQueue: nil)
            storedSession = created
            return created
        }
    }

    /// Reconnects to transfers from an earlier launch; finished chunks are reported to `handler`.
    func activate(handler: @escaping @Sendable (_ uploadID: String, _ number: Int, _ outcome: Outcome) -> Void) {
        lock.withLock { self.handler = handler }
        _ = session
    }

    /// iOS woke the app for this session's events; call `completion` once they are all delivered.
    func handleEvents(for identifier: String, completion: @escaping () -> Void) {
        guard identifier == Self.identifier else { return }
        lock.withLock { eventsCompletion = completion }
        _ = session
    }

    static var rootDirectory: URL {
        URL.applicationSupportDirectory.appending(path: "ImportChunks", directoryHint: .isDirectory)
    }

    /// Creates the upload's chunk folder (kept out of iCloud backups).
    static func prepareDirectory(for uploadID: String) throws {
        var root = rootDirectory
        try FileManager.default.createDirectory(at: directory(for: uploadID), withIntermediateDirectories: true)
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? root.setResourceValues(values)
    }

    static func directory(for uploadID: String) -> URL {
        rootDirectory
            .appending(path: uploadID, directoryHint: .isDirectory)
    }

    static func file(for uploadID: String, number: Int) -> URL {
        directory(for: uploadID).appending(path: "\(number).part")
    }

    func enqueue(_ request: URLRequest, uploadID: String, number: Int) {
        let task = session.uploadTask(with: request, fromFile: Self.file(for: uploadID, number: number))
        task.taskDescription = "\(uploadID)/\(number)"
        task.resume()
    }

    /// Chunks of this upload iOS is still sending (or waiting to send).
    func pending(uploadID: String) async -> Set<Int> {
        let tasks = await session.allTasks
        return Set(tasks.compactMap { task -> Int? in
            guard task.state != .completed, task.state != .canceling, let parsed = Self.parse(task) else { return nil }
            return parsed.uploadID == uploadID ? parsed.number : nil
        })
    }

    /// Uploads with chunks still in flight, so leftovers of the others can be deleted.
    func activeUploads() async -> Set<String> {
        let tasks = await session.allTasks
        return Set(tasks.compactMap { Self.parse($0)?.uploadID })
    }

    func cancel(uploadID: String) async {
        for task in await session.allTasks where Self.parse(task)?.uploadID == uploadID {
            task.cancel()
        }
        try? FileManager.default.removeItem(at: Self.directory(for: uploadID))
    }

    private static func parse(_ task: URLSessionTask) -> (uploadID: String, number: Int)? {
        guard let parts = task.taskDescription?.split(separator: "/"), parts.count == 2, let number = Int(parts[1]) else { return nil }
        return (String(parts[0]), number)
    }

    // MARK: URLSessionDataDelegate

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: (any Error)?) {
        guard let (uploadID, number) = Self.parse(task) else { return }
        if (error as? URLError)?.code == .cancelled {
            return
        }
        let status = (task.response as? HTTPURLResponse)?.statusCode ?? 0
        let outcome: Outcome
        if error == nil, (200..<300).contains(status) {
            try? FileManager.default.removeItem(at: Self.file(for: uploadID, number: number))
            outcome = .stored
        } else {
            log.error("import chunk \(number) failed: status \(status), \(String(describing: error), privacy: .public)")
            outcome = .failed(transient: error != nil || status == 0 || status == 429 || status >= 500, status: status)
        }
        lock.withLock { handler }?(uploadID, number, outcome)
    }

    func urlSessionDidFinishEvents(forBackgroundURLSession session: URLSession) {
        let completion = lock.withLock { () -> (() -> Void)? in
            defer { eventsCompletion = nil }
            return eventsCompletion
        }
        guard let completion else { return }
        nonisolated(unsafe) let call = completion
        DispatchQueue.main.async { call() }
    }
}
