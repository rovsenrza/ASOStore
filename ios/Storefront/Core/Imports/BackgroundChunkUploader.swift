import Foundation
import OSLog

/// What `ImportCenter` needs from the background chunk session (a fake in tests).
nonisolated protocol ChunkUploading: AnyObject, Sendable {
    typealias Handler = @Sendable (_ uploadID: String, _ number: Int, _ outcome: BackgroundChunkUploader.Outcome) -> Void
    /// Attaches the receiver of finished chunks; events that arrived earlier are delivered now.
    func activate(handler: @escaping Handler)
    func enqueue(_ request: URLRequest, uploadID: String, number: Int)
    func pending(uploadID: String) async -> Set<Int>
    func activeUploads() async -> Set<String>
    func cancel(uploadID: String) async
}

/// Sends import chunks through an iOS background URLSession, so an upload keeps going after the
/// customer leaves the app (to install another app, say) or iOS suspends or closes it. Chunks are
/// handed over as files (Application Support/ImportChunks/<upload>/<n>.part; not Caches, which iOS
/// may purge before they are sent); `nsurlsessiond` sends them and wakes the app when they are done.
/// The server authenticates them with the upload's own token and completes the upload itself when
/// the last chunk lands.
///
/// The app delegate connects the session at launch, before SwiftUI builds `ImportCenter`; chunk
/// results that arrive before `ImportCenter` attaches its handler are buffered, not dropped.
nonisolated final class BackgroundChunkUploader: NSObject, URLSessionDataDelegate, ChunkUploading, @unchecked Sendable {
    enum Outcome: Sendable, Equatable {
        case stored
        /// `transient`: worth sending again (dropped connection, busy server).
        case failed(transient: Bool, status: Int)

        /// The upload token was refused or expired: worth refreshing it and sending again.
        var isUnauthorized: Bool {
            if case .failed(_, let status) = self { return status == 401 || status == 403 }
            return false
        }
    }

    static let shared = BackgroundChunkUploader()
    static let defaultIdentifier = "storefront.import-chunks"

    let identifier: String
    private let log = Logger(subsystem: "storefront", category: "imports")
    private let lock = NSLock()
    private var handler: Handler?
    /// Chunk results that arrived before a handler was attached (a background relaunch).
    private var buffered: [(uploadID: String, number: Int, outcome: Outcome)] = []
    private var eventsCompletion: (() -> Void)?
    private var storedSession: URLSession?

    init(identifier: String = BackgroundChunkUploader.defaultIdentifier) {
        self.identifier = identifier
        super.init()
    }

    private var session: URLSession {
        lock.withLock {
            if let storedSession {
                return storedSession
            }
            let configuration = URLSessionConfiguration.background(withIdentifier: identifier)
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

    /// Reconnects to transfers from an earlier launch (call at app launch). Their results are
    /// buffered until `activate(handler:)`.
    func connect() {
        _ = session
    }

    func activate(handler: @escaping Handler) {
        attach(handler)
        connect()
    }

    /// Sets the handler and hands it everything buffered so far, in arrival order.
    func attach(_ handler: @escaping Handler) {
        let pending = lock.withLock { () -> [(uploadID: String, number: Int, outcome: Outcome)] in
            self.handler = handler
            defer { buffered.removeAll() }
            return buffered
        }
        for event in pending {
            handler(event.uploadID, event.number, event.outcome)
        }
    }

    /// iOS woke the app for this session's events; `completion` runs once they are all delivered
    /// (to the handler, or into the buffer). Another session's events are not ours: complete at once.
    func handleEvents(for identifier: String, completion: @escaping () -> Void) {
        guard identifier == self.identifier else {
            completion()
            return
        }
        lock.withLock { eventsCompletion = completion }
        connect()
    }

    /// Reports one chunk result, or keeps it until a handler is attached.
    func deliver(uploadID: String, number: Int, outcome: Outcome) {
        let current = lock.withLock { () -> Handler? in
            if handler == nil {
                buffered.append((uploadID, number, outcome))
            }
            return handler
        }
        current?(uploadID, number, outcome)
    }

    /// Results waiting for a handler (tests and diagnostics).
    var bufferedCount: Int { lock.withLock { buffered.count } }

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
        deliver(uploadID: uploadID, number: number, outcome: outcome)
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
