import CryptoKit
import Foundation
import Combine
import OSLog
import UIKit

/// The customer's own IPA imports: from Files (or «Открыть в Ru App Store» from another app),
/// sent to the server in chunks, or from a link the server downloads. The server inspects each
/// import like any upload; installing one goes through the usual installation flow.
///
/// Server state is the source of truth; the center only adds the uploads running on this device,
/// which the server cannot report progress for.
@MainActor
final class ImportCenter: ObservableObject {
    /// An upload from this device, shown above the server list until the server has the file.
    struct Upload: Identifiable, Equatable {
        enum Phase: Equatable {
            case checking
            case uploading
            case finishing
            /// iOS stopped it after the app left the screen; it continues once the app is back.
            case paused
            case failed(String)
        }

        let id: UUID
        let name: String
        var progress: Double = 0
        var phase: Phase = .checking
        /// Known once the server opened the upload; the server row with this id is hidden meanwhile.
        var importID: String?
    }

    @Published private(set) var imports: [ImportDTO] = []
    @Published private(set) var hasLoaded = false
    @Published private(set) var uploads: [Upload] = []
    /// A file another app sent with «Открыть в Ru App Store», waiting for the customer to confirm.
    @Published var incomingFile: URL?
    /// Shown as an alert by the Manager screen.
    @Published var message: String?

    private let repository: ImportRepository
    private let installations: InstallationCoordinator?
    /// Sends chunks through an iOS background session (nil in tests and mock mode: chunks then go
    /// one by one through the API client while the app is open).
    private let uploader: (any ChunkUploading)?
    /// Running uploads saved across launches (nil in tests and mock mode).
    private let store: ImportUploadStore?
    private let retryPolicy: ImportRetryPolicy
    private let reachability: any NetworkReachability
    /// Background uploads waiting for their last chunk, by server upload id.
    private var waiters: [String: CheckedContinuation<Void, any Error>] = [:]
    private var tasks: [UUID: Task<Void, Never>] = [:]
    /// Delayed re-sends of background chunks (and token refreshes), per upload and chunk.
    private var retryTasks: [UUID: [Int: Task<Void, Never>]] = [:]
    private var tokenRefreshes: [UUID: Task<TokenRefresh, Never>] = [:]
    private var sources: [UUID: Source] = [:]
    private var poll: Task<Void, Never>?
    /// Uploads stopped by something other than the customer (the app left the screen, the network
    /// dropped); they continue from the same chunk when the app is back in front.
    private var interrupted: Set<UUID> = []
    private var backgroundTasks: [UUID: UIBackgroundTaskIdentifier] = [:]
    private let log = Logger(subsystem: "storefront", category: "imports")

    /// What POST /imports was called with (and is called with again to ask what the server has).
    private struct FileInfo {
        let name: String
        let size: Int64
        let sha256: String
    }

    /// Where an upload reads from, and what is left to send if it is retried.
    private struct Source {
        let url: URL
        let scoped: Bool
        /// A copy iOS put in Documents/Inbox for us; deleted once the upload is over.
        let ownsFile: Bool
        var bookmark: Data?
        var file: FileInfo?
        var session: ImportUploadDTO?
        var sent: Set<Int> = []
        /// Chunks were handed to the background session: they keep going while the app is away.
        var background = false
        var hashes: [Int: String] = [:]
        var failures: [Int: Int] = [:]
        /// Rebuilt after a relaunch: ask the server which chunks it has before sending more.
        var needsServerState = false
        /// The upload token cannot be used (expired, refused, and no refresh endpoint): the
        /// remaining chunks go from the open app with the customer's access token.
        var tokenUnusable = false
        /// The server closed the upload (409 no longer OPEN, 410 expired): nothing more can be sent.
        var terminal: APIError?
    }

    private enum TokenRefresh {
        case refreshed
        /// No usable answer (an older server without the endpoint, say): send from the app.
        case unavailable
        case terminal(APIError)
    }

    init(
        repository: ImportRepository,
        installations: InstallationCoordinator?,
        uploader: (any ChunkUploading)? = nil,
        store: ImportUploadStore? = nil,
        retryPolicy: ImportRetryPolicy = .standard,
        reachability: any NetworkReachability = AlwaysOnline()
    ) {
        self.repository = repository
        self.installations = installations
        self.uploader = uploader
        self.store = store
        self.retryPolicy = retryPolicy
        self.reachability = reachability
        if let uploader {
            // Results buffered since launch (a background relaunch) are delivered right here.
            uploader.activate { [weak self] uploadID, number, outcome in
                Task { @MainActor in self?.chunkFinished(uploadID: uploadID, number: number, outcome: outcome) }
            }
        }
        restoreSavedUploads()
        if let uploader {
            Task { [weak self] in
                let active = await uploader.activeUploads()
                self?.removeLeftoverChunks(keeping: active)
            }
        }
        // Customers leave mid-upload (to install another app, say); carry on when they come back.
        NotificationCenter.default.addObserver(forName: UIApplication.didBecomeActiveNotification, object: nil, queue: .main) { [weak self] _ in
            MainActor.assumeIsolated { self?.resumeInterrupted() }
        }
    }

    /// Imports to list: server rows, minus the ones still uploading from here.
    var visibleImports: [ImportDTO] {
        let uploading = Set(uploads.compactMap(\.importID))
        return imports.filter { !uploading.contains($0.id) }
    }

    // MARK: Loading

    func refresh() async {
        do {
            imports = try await repository.list()
            hasLoaded = true
        } catch is CancellationError {
        } catch let error as APIError where error.code == .unauthenticated || error.code == .sessionExpired {
            imports = []
            hasLoaded = true
        } catch {
            log.error("imports refresh failed: \(String(describing: error), privacy: .public)")
        }
        schedulePollIfNeeded()
    }

    /// While the server is still downloading or inspecting something, check back every few seconds.
    private func schedulePollIfNeeded() {
        guard poll == nil, imports.contains(where: \.isProcessing) else { return }
        poll = Task { [weak self] in
            try? await Task.sleep(for: .seconds(3))
            guard let self, !Task.isCancelled else { return }
            self.poll = nil
            await self.refresh()
        }
    }

    // MARK: From a link

    /// Returns true once the server accepted the link (the download runs on the server).
    func importLink(_ text: String) async -> Bool {
        let link = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let url = URL(string: link), url.scheme?.lowercased() == "https", url.host() != nil else {
            message = "Нужна ссылка, начинающаяся с https://."
            return false
        }
        do {
            let created = try await repository.startLink(url.absoluteString)
            imports.removeAll { $0.id == created.id }
            imports.insert(created, at: 0)
            schedulePollIfNeeded()
            return true
        } catch let error as APIError {
            message = Self.message(for: error)
            return false
        } catch {
            message = "Не удалось начать импорт. Попробуйте ещё раз."
            return false
        }
    }

    // MARK: From Files

    /// Starts uploading a file chosen in Files, or one another app opened in Ru App Store.
    func importFile(at url: URL, ownsFile: Bool = false) {
        let scoped = url.startAccessingSecurityScopedResource()
        let upload = Upload(id: UUID(), name: url.deletingPathExtension().lastPathComponent)
        uploads.insert(upload, at: 0)
        sources[upload.id] = Source(url: url, scoped: scoped, ownsFile: ownsFile, bookmark: store == nil ? nil : ImportUploadStore.bookmark(for: url))
        run(upload.id)
    }

    /// The customer confirmed the file another app sent.
    func acceptIncomingFile() {
        guard let url = incomingFile else { return }
        incomingFile = nil
        importFile(at: url, ownsFile: true)
    }

    func declineIncomingFile() {
        if let url = incomingFile {
            try? FileManager.default.removeItem(at: url)
        }
        incomingFile = nil
    }

    func retry(_ uploadID: UUID) {
        guard tasks[uploadID] == nil, sources[uploadID] != nil else { return }
        run(uploadID)
    }

    /// Stops an upload; the half-sent import is deleted on the server so it does not take a slot.
    func cancel(_ uploadID: UUID) async {
        tasks[uploadID]?.cancel()
        tasks[uploadID] = nil
        cancelRetries(uploadID)
        let importID = uploads.first { $0.id == uploadID }?.importID
        if let session = sources[uploadID]?.session {
            waiters.removeValue(forKey: session.id)?.resume(throwing: CancellationError())
            if sources[uploadID]?.background == true {
                await uploader?.cancel(uploadID: session.id)
            }
        }
        finish(uploadID)
        if let importID {
            try? await repository.delete(importID: importID)
            await refresh()
        }
    }

    private func run(_ id: UUID) {
        interrupted.remove(id)
        let resuming = sources[id]?.session != nil
        update(id) { $0.phase = resuming ? .uploading : .checking }
        tasks[id] = Task { [weak self] in
            await self?.upload(id)
        }
    }

    private func resumeInterrupted() {
        for id in interrupted where tasks[id] == nil && sources[id] != nil {
            run(id)
        }
    }

    private func upload(_ id: UUID) async {
        guard var source = sources[id] else { return }
        beginBackground(id)
        defer {
            endBackground(id)
            tasks[id] = nil
        }

        do {
            if source.session == nil {
                let file = try await Self.describe(source.url) { [weak self] fraction in
                    Task { @MainActor in self?.update(id) { $0.progress = fraction * 0.05 } }
                }
                let info = FileInfo(name: file.name, size: file.size, sha256: file.sha256)
                let session = try await retrying { try await self.repository.startUpload(filename: info.name, sizeBytes: info.size, sha256: info.sha256) }
                source.file = info
                source.session = session
                source.sent = Set(session.receivedChunks)
                sources[id] = source
                update(id) { $0.importID = session.importId }
                persist(id)
            } else if source.needsServerState {
                guard try await reconcileWithServer(id) else { return }
            }
            guard let session = sources[id]?.session else { return }

            let sentBefore = sources[id]?.sent.count ?? 0
            update(id) {
                $0.phase = .uploading
                $0.progress = 0.05 + Double(sentBefore) / Double(max(session.chunkCount, 1)) * 0.93
            }
            if uploader != nil, session.uploadToken != nil, sources[id]?.tokenUnusable == false {
                await refreshTokenIfExpiring(id)
                if sources[id]?.tokenUnusable == false, let current = sources[id]?.session {
                    _ = try await uploadInBackground(id, session: current)
                }
            }
            if let terminal = sources[id]?.terminal { throw terminal }
            // Whatever the background session did not deliver (no room to stage it, a refused
            // token, a chunk that kept failing) goes from the open app.
            for number in 0..<session.chunkCount where sources[id]?.sent.contains(number) == false {
                try Task.checkCancellation()
                guard let url = sources[id]?.url else { return }
                let chunk = try await Self.readChunk(url, number: number, size: session.chunkSize)
                let digest = Self.hex(SHA256.hash(data: chunk))
                try await retrying { try await self.repository.putChunk(uploadID: session.id, number: number, data: chunk, sha256: digest) }
                markSent(id, number: number)
            }

            // Never ask the server to assemble a file it does not have all of.
            guard (sources[id]?.sent.count ?? 0) >= session.chunkCount else {
                throw APIError(code: .networkError)
            }
            update(id) { $0.phase = .finishing }
            _ = try await retrying { try await self.repository.complete(uploadID: session.id) }
            // Fetch the server row first, so the import never disappears between the two lists.
            await refresh()
            finish(id)
        } catch is CancellationError {
            // The customer's own cancel removes the upload first; anything else is iOS stopping us.
            guard let source = sources[id] else { return }
            interrupted.insert(id)
            // Background chunks keep going while the app is away; only the waiting stopped.
            update(id) { $0.phase = source.background ? .uploading : .paused }
        } catch let error as APIError {
            if Self.isTransient(error) {
                interrupted.insert(id)
            }
            update(id) { $0.phase = .failed(Self.message(for: error)) }
        } catch {
            log.error("import upload failed: \(String(describing: error), privacy: .public)")
            update(id) { $0.phase = .failed("Не удалось прочитать файл.") }
        }
    }

    /// A dropped connection or a busy server costs a wait, not the upload. Waits grow with jitter
    /// (`retryPolicy`) and stop after `maxRetries`; then the upload pauses and continues when the
    /// app is next opened. While the device is offline it first waits (without polling) for a
    /// network path to come back, however long that takes.
    private func retrying<Value>(_ operation: () async throws -> Value) async throws -> Value {
        var attempt = 0
        while true {
            do {
                return try await operation()
            } catch let error as APIError where Self.isTransient(error) {
                if error.code == .offline || !reachability.isOnline {
                    await reachability.waitUntilOnline()
                    try Task.checkCancellation()
                }
                attempt += 1
                guard attempt <= retryPolicy.maxRetries else { throw error }
                try await Task.sleep(for: retryPolicy.delay(forAttempt: attempt))
            }
        }
    }

    private func markSent(_ id: UUID, number: Int) {
        guard var source = sources[id], let session = source.session else { return }
        source.sent.insert(number)
        source.failures[number] = nil
        sources[id] = source
        let fraction = Double(source.sent.count) / Double(max(session.chunkCount, 1))
        update(id) { $0.progress = 0.05 + fraction * 0.93 }
        persist(id)
    }

    // MARK: Saved uploads

    /// Writes the upload's state to disk, so a relaunch can continue it.
    private func persist(_ id: UUID) {
        guard let store, let source = sources[id], let session = source.session, let file = source.file,
              let upload = uploads.first(where: { $0.id == id }) else { return }
        store.upsert(PersistedImportUpload(
            id: id,
            name: upload.name,
            filename: file.name,
            sizeBytes: file.size,
            sha256: file.sha256,
            uploadID: session.id,
            importID: session.importId,
            uploadToken: session.uploadToken,
            uploadTokenExpiresAt: session.uploadTokenExpiresAt,
            chunkSize: session.chunkSize,
            chunkCount: session.chunkCount,
            chunkHashes: source.hashes,
            sentChunks: source.sent.sorted(),
            background: source.background,
            bookmark: source.bookmark,
            stagedPath: source.url.path,
            ownsFile: source.ownsFile
        ))
    }

    /// Rebuilds uploads saved by an earlier launch and continues them.
    private func restoreSavedUploads() {
        guard let store else { return }
        for record in store.load() where sources[record.id] == nil {
            guard let url = ImportUploadStore.resolve(record) else {
                log.error("import upload \(record.uploadID, privacy: .public): file gone after relaunch")
                store.remove(record.id)
                continue
            }
            let scoped = url.startAccessingSecurityScopedResource()
            let session = ImportUploadDTO(
                id: record.uploadID,
                importId: record.importID,
                status: "OPEN",
                chunkSize: record.chunkSize,
                chunkCount: record.chunkCount,
                receivedChunks: record.sentChunks,
                missingChunks: [],
                uploadToken: record.uploadToken,
                uploadTokenExpiresAt: record.uploadTokenExpiresAt
            )
            var source = Source(url: url, scoped: scoped, ownsFile: record.ownsFile, bookmark: record.bookmark)
            source.file = FileInfo(name: record.filename, size: record.sizeBytes, sha256: record.sha256)
            source.session = session
            source.sent = Set(record.sentChunks)
            source.background = record.background
            source.hashes = record.chunkHashes
            source.needsServerState = true
            sources[record.id] = source
            var upload = Upload(id: record.id, name: record.name)
            upload.importID = record.importID
            upload.progress = 0.05 + Double(record.sentChunks.count) / Double(max(record.chunkCount, 1)) * 0.93
            upload.phase = .uploading
            uploads.append(upload)
            run(record.id)
        }
    }

    /// After a relaunch: asks the server how far the upload got. Returns false when there is
    /// nothing left to do here (the server already has the file, or the import is gone).
    private func reconcileWithServer(_ id: UUID) async throws -> Bool {
        guard let source = sources[id], let file = source.file, let old = source.session else { return false }
        let rows = try await retrying { try await self.repository.list() }
        imports = rows
        hasLoaded = true
        guard let row = rows.first(where: { $0.id == old.importId }), row.isUnfinishedUpload else {
            // Finished while the app was closed (the last background chunk completes it on the
            // server), or deleted: either way the server list now tells the story.
            finish(id)
            schedulePollIfNeeded()
            return false
        }
        // The token endpoint answers with a fresh token and the chunks the server has; servers
        // without it: POST /imports with the same file returns the open upload the same way.
        let fresh: ImportUploadDTO
        do {
            fresh = try await retrying { try await self.repository.refreshUploadToken(uploadID: old.id) }
        } catch let error as APIError where Self.isTerminal(error) {
            throw error
        } catch is CancellationError {
            throw CancellationError()
        } catch {
            fresh = try await retrying { try await self.repository.startUpload(filename: file.name, sizeBytes: file.size, sha256: file.sha256) }
        }
        guard var current = sources[id] else { return false }
        current.needsServerState = false
        if fresh.id == old.id {
            var merged = fresh
            if merged.uploadToken == nil {
                merged.uploadToken = old.uploadToken
                merged.uploadTokenExpiresAt = old.uploadTokenExpiresAt
            }
            current.session = merged
            current.sent = Set(fresh.receivedChunks)
        } else {
            // The old upload expired on the server: this is a new one, from what it has.
            current.session = fresh
            current.sent = Set(fresh.receivedChunks)
            current.hashes = [:]
            current.failures = [:]
            current.background = false
            current.tokenUnusable = false
        }
        sources[id] = current
        update(id) { $0.importID = fresh.importId }
        persist(id)
        return true
    }

    // MARK: Upload token

    /// Refreshes the upload token when it expires within two minutes. If that fails and the token
    /// has already expired, the remaining chunks go from the open app instead.
    private func refreshTokenIfExpiring(_ id: UUID) async {
        guard let expiry = sources[id]?.session?.uploadTokenExpiry, expiry.timeIntervalSinceNow < 120 else { return }
        switch await refreshToken(id) {
        case .refreshed: break
        case .terminal: sources[id]?.tokenUnusable = true
        case .unavailable:
            if expiry.timeIntervalSinceNow <= 0 { sources[id]?.tokenUnusable = true }
        }
    }

    /// 409 (no longer OPEN) and 410 (expired) from the token endpoint end the upload.
    nonisolated static func isTerminal(_ error: APIError) -> Bool {
        error.status == 409 || error.status == 410
    }

    /// Asks the server for a new upload token (once at a time per upload), POST /imports/{upload}/token.
    private func refreshToken(_ id: UUID) async -> TokenRefresh {
        if let running = tokenRefreshes[id] {
            return await running.value
        }
        guard let uploadID = sources[id]?.session?.id else { return .unavailable }
        let task = Task { [weak self] () -> TokenRefresh in
            guard let self else { return .unavailable }
            do {
                let fresh = try await self.retrying { try await self.repository.refreshUploadToken(uploadID: uploadID) }
                guard self.sources[id]?.session?.id == uploadID, let token = fresh.uploadToken else { return .unavailable }
                self.sources[id]?.session?.uploadToken = token
                self.sources[id]?.session?.uploadTokenExpiresAt = fresh.uploadTokenExpiresAt
                self.sources[id]?.sent.formUnion(fresh.receivedChunks)
                self.persist(id)
                return .refreshed
            } catch let error as APIError where Self.isTerminal(error) {
                self.log.error("import upload closed on the server: status \(error.status)")
                self.sources[id]?.terminal = error
                return .terminal(error)
            } catch {
                self.log.error("import upload token refresh failed: \(String(describing: error), privacy: .public)")
                return .unavailable
            }
        }
        tokenRefreshes[id] = task
        let refreshed = await task.value
        tokenRefreshes[id] = nil
        return refreshed
    }

    // MARK: Background session

    /// Hands the chunks not yet on the server to the background session and waits until they all
    /// are, or until the session cannot deliver some (then the caller sends those itself). Returns
    /// false (nothing handed over) when there is no room to stage them as files.
    private func uploadInBackground(_ id: UUID, session: ImportUploadDTO) async throws -> Bool {
        guard let uploader, let url = sources[id]?.url else { return false }
        let inFlight = await uploader.pending(uploadID: session.id)
        let missing = (0..<session.chunkCount).filter { sources[id]?.sent.contains($0) == false && !inFlight.contains($0) }
        if !missing.isEmpty {
            // No room to stage them: send what is left from the open app (a chunk the background
            // session also sends is simply acknowledged twice).
            guard Self.hasRoom(for: Int64(missing.count) * Int64(session.chunkSize)) else { return false }
            try BackgroundChunkUploader.prepareDirectory(for: session.id)
            sources[id]?.background = true
            for number in missing {
                try Task.checkCancellation()
                guard let token = sources[id]?.session?.uploadToken, sources[id]?.tokenUnusable == false else { break }
                let digest = try await Self.stageChunk(url, number: number, size: session.chunkSize, to: BackgroundChunkUploader.file(for: session.id, number: number))
                sources[id]?.hashes[number] = digest
                uploader.enqueue(try await repository.backgroundChunkRequest(uploadID: session.id, number: number, token: token, sha256: digest), uploadID: session.id, number: number)
            }
            persist(id)
        } else if !inFlight.isEmpty {
            sources[id]?.background = true
        }

        guard sources[id]?.background == true else { return false }
        try Task.checkCancellation()
        if (sources[id]?.sent.count ?? 0) < session.chunkCount, sources[id]?.tokenUnusable == false {
            try await withTaskCancellationHandler {
                try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                    waiters.removeValue(forKey: session.id)?.resume(throwing: CancellationError())
                    if (sources[id]?.sent.count ?? 0) >= session.chunkCount || sources[id]?.tokenUnusable == true {
                        continuation.resume()
                    } else {
                        waiters[session.id] = continuation
                    }
                }
            } onCancel: {
                Task { @MainActor [weak self] in self?.waiters.removeValue(forKey: session.id)?.resume(throwing: CancellationError()) }
            }
        }
        return true
    }

    /// A chunk the background session finished (also ones from before the app was relaunched).
    private func chunkFinished(uploadID: String, number: Int, outcome: BackgroundChunkUploader.Outcome) {
        guard let id = sources.first(where: { $0.value.session?.id == uploadID })?.key, let session = sources[id]?.session else { return }
        if outcome == .stored {
            markSent(id, number: number)
            if (sources[id]?.sent.count ?? 0) >= session.chunkCount {
                waiters.removeValue(forKey: uploadID)?.resume()
            }
            return
        }
        guard case .failed(let transient, _) = outcome else { return }
        let failures = (sources[id]?.failures[number] ?? 0) + 1
        sources[id]?.failures[number] = failures
        let unauthorized = outcome.isUnauthorized
        guard transient || unauthorized, failures <= retryPolicy.maxRetries else {
            // Refused for good, or failing again and again: the open app sends what is left.
            log.error("import chunk \(number) handed back to the app after \(failures) failure(s)")
            waiters.removeValue(forKey: uploadID)?.resume()
            return
        }
        let delay = retryPolicy.delay(forAttempt: failures)
        schedule(id, number: number) { [weak self] in
            guard let self else { return }
            if unauthorized {
                // The upload token expired or was refused: get a new one and send the chunk again.
                switch await self.refreshToken(id) {
                case .refreshed:
                    break
                case .terminal(let error):
                    self.sources[id]?.tokenUnusable = true
                    self.waiters.removeValue(forKey: uploadID)?.resume(throwing: error)
                    return
                case .unavailable:
                    self.sources[id]?.tokenUnusable = true
                    self.waiters.removeValue(forKey: uploadID)?.resume()
                    return
                }
            } else {
                try? await Task.sleep(for: delay)
                await self.reachability.waitUntilOnline()
            }
            guard !Task.isCancelled else { return }
            await self.sendAgain(id, number: number)
        }
    }

    /// Runs a delayed re-send of one chunk, replacing an earlier one for the same chunk.
    private func schedule(_ id: UUID, number: Int, _ work: @escaping @MainActor () async -> Void) {
        retryTasks[id]?[number]?.cancel()
        retryTasks[id, default: [:]][number] = Task { @MainActor in await work() }
    }

    private func cancelRetries(_ id: UUID) {
        retryTasks.removeValue(forKey: id)?.values.forEach { $0.cancel() }
        tokenRefreshes.removeValue(forKey: id)?.cancel()
    }

    /// Sends one chunk through the background session again. Its hash (and staged file) is made
    /// again from the original when this launch does not have it (a chunk that was in flight
    /// before a relaunch, say); if that is impossible, the open app sends it instead.
    private func sendAgain(_ id: UUID, number: Int) async {
        guard let uploader, let source = sources[id], let session = source.session,
              !source.sent.contains(number) else { return }
        guard let token = session.uploadToken, !source.tokenUnusable else {
            waiters.removeValue(forKey: session.id)?.resume()
            return
        }
        do {
            let staged = BackgroundChunkUploader.file(for: session.id, number: number)
            var digest = source.hashes[number]
            if digest == nil || !FileManager.default.fileExists(atPath: staged.path) {
                try BackgroundChunkUploader.prepareDirectory(for: session.id)
                digest = try await Self.stageChunk(source.url, number: number, size: session.chunkSize, to: staged)
                sources[id]?.hashes[number] = digest
                persist(id)
            }
            guard let digest, sources[id]?.sent.contains(number) == false else { return }
            uploader.enqueue(try await repository.backgroundChunkRequest(uploadID: session.id, number: number, token: token, sha256: digest), uploadID: session.id, number: number)
        } catch {
            log.error("import chunk \(number) could not be staged again: \(String(describing: error), privacy: .public)")
            waiters.removeValue(forKey: session.id)?.resume()
        }
    }

    nonisolated private static func hasRoom(for bytes: Int64) -> Bool {
        // Ask about the container's volume: Application Support does not exist before the first
        // staged chunk, and asking about a missing folder fails (every first import then went
        // chunk by chunk from the open app).
        let values = try? URL(fileURLWithPath: NSHomeDirectory(), isDirectory: true)
            .resourceValues(forKeys: [.volumeAvailableCapacityForImportantUsageKey, .volumeAvailableCapacityKey])
        // The "important usage" figure is missing on some volumes (the simulator): use the plain one.
        guard let free = values?.volumeAvailableCapacityForImportantUsage ?? values?.volumeAvailableCapacity.map(Int64.init) else { return false }
        return free > bytes + 500 * 1024 * 1024
    }

    /// Deletes chunk files of uploads the background session no longer sends (finished, or from
    /// a launch whose upload was abandoned).
    private func removeLeftoverChunks(keeping active: Set<String>) {
        let running = Set(sources.values.compactMap(\.session?.id))
        let root = BackgroundChunkUploader.rootDirectory
        let folders = (try? FileManager.default.contentsOfDirectory(at: root, includingPropertiesForKeys: nil)) ?? []
        for folder in folders where !active.contains(folder.lastPathComponent) && !running.contains(folder.lastPathComponent) {
            try? FileManager.default.removeItem(at: folder)
        }
    }

    nonisolated static func isTransient(_ error: APIError) -> Bool {
        [.offline, .networkError, .serviceUnavailable, .rateLimited].contains(error.code)
            || (error.code == .invalidResponse && error.status >= 500)
    }

    nonisolated private static func hex(_ digest: SHA256.Digest) -> String {
        digest.map { String(format: "%02x", $0) }.joined()
    }

    /// iOS gives an app some time after it leaves the screen; the upload keeps going meanwhile.
    /// When that runs out it stops cleanly (iOS would otherwise end the app) and is resumed later.
    private func beginBackground(_ id: UUID) {
        backgroundTasks[id] = UIApplication.shared.beginBackgroundTask(withName: "ipa-import") { [weak self] in
            MainActor.assumeIsolated {
                self?.tasks[id]?.cancel()
                self?.endBackground(id)
            }
        }
    }

    private func endBackground(_ id: UUID) {
        guard let task = backgroundTasks.removeValue(forKey: id) else { return }
        UIApplication.shared.endBackgroundTask(task)
    }

    private func finish(_ id: UUID) {
        interrupted.remove(id)
        cancelRetries(id)
        store?.remove(id)
        if let source = sources.removeValue(forKey: id) {
            if let session = source.session {
                waiters.removeValue(forKey: session.id)?.resume(throwing: CancellationError())
                if source.background {
                    try? FileManager.default.removeItem(at: BackgroundChunkUploader.directory(for: session.id))
                }
            }
            if source.scoped {
                source.url.stopAccessingSecurityScopedResource()
            }
            if source.ownsFile {
                try? FileManager.default.removeItem(at: source.url)
            }
        }
        uploads.removeAll { $0.id == id }
    }

    private func update(_ id: UUID, _ change: (inout Upload) -> Void) {
        guard let index = uploads.firstIndex(where: { $0.id == id }) else { return }
        change(&uploads[index])
    }

    // MARK: Install and delete

    func install(_ item: ImportDTO) async {
        do {
            let installation = try await repository.install(importID: item.id)
            installations?.follow(installation)
            await refresh()
        } catch let error as APIError {
            message = error.code == .conflict ? Self.message(for: error) : InstallationCoordinator.message(for: error)
        } catch {
            message = "Повторите попытку позже."
        }
    }

    func delete(_ item: ImportDTO) async {
        do {
            try await repository.delete(importID: item.id)
            imports.removeAll { $0.id == item.id }
        } catch let error as APIError where error.code == .notFound {
            imports.removeAll { $0.id == item.id }
        } catch let error as APIError {
            message = Self.message(for: error)
        } catch {
            message = "Не удалось удалить импорт."
        }
    }

    // MARK: Copy

    static func message(for error: APIError) -> String {
        switch error.code {
        // The server says which limit was hit (daily vs total), so prefer its wording.
        case .quotaExhausted: error.message.isEmpty ? "Достигнут предел импортов. Удалите старый импорт или попробуйте завтра." : error.message
        case .offline, .networkError: "Нет связи с сервером. Проверьте интернет."
        case .rateLimited: "Слишком много попыток. Подождите минуту."
        case .unauthenticated, .sessionExpired: "Войдите в аккаунт, чтобы импортировать приложения."
        default: error.message.isEmpty ? "Повторите попытку позже." : error.message
        }
    }

    /// Why an import failed, in words (the server sends a code, or a sentence for a link).
    static func failureText(for item: ImportDTO) -> String {
        if let reason = item.failureReason, reason.contains(" ") {
            return reason
        }
        switch item.failureReason {
        case "ENCRYPTED_BINARY": return "Файл зашифрован App Store. Нужна расшифрованная копия IPA."
        case "DUPLICATE_ARTIFACT": return "Этот файл уже загружен."
        case "UPLOAD_CORRUPT": return "Файл повредился при загрузке. Импортируйте его ещё раз."
        default: break
        }
        switch item.status {
        case "UPLOAD_FAILED": return "Загрузка не завершилась."
        case "DOWNLOAD_FAILED": return "Не удалось скачать файл по ссылке."
        case "QUARANTINED": return "Файл не прошёл проверку безопасности."
        default: return "Файл не прошёл проверку. Возможно, это не IPA или он повреждён."
        }
    }

    // MARK: Files (off the main actor)

    nonisolated private static func describe(_ url: URL, progress: @escaping @Sendable (Double) -> Void) async throws -> (name: String, size: Int64, sha256: String) {
        try await Task.detached(priority: .userInitiated) {
            let size = Int64(try url.resourceValues(forKeys: [.fileSizeKey]).fileSize ?? 0)
            let handle = try FileHandle(forReadingFrom: url)
            defer { try? handle.close() }
            var hasher = SHA256()
            var read: Int64 = 0
            while let data = try handle.read(upToCount: 4 * 1024 * 1024), !data.isEmpty {
                try Task.checkCancellation()
                hasher.update(data: data)
                read += Int64(data.count)
                if size > 0 { progress(Double(read) / Double(size)) }
            }
            let digest = hasher.finalize().map { String(format: "%02x", $0) }.joined()
            var name = url.lastPathComponent
            if !name.lowercased().hasSuffix(".ipa") { name += ".ipa" }
            return (name, read, digest)
        }.value
    }

    /// Copies one chunk of the file into its own file for the background session; returns its SHA-256.
    nonisolated private static func stageChunk(_ url: URL, number: Int, size: Int, to destination: URL) async throws -> String {
        try await Task.detached(priority: .userInitiated) {
            let handle = try FileHandle(forReadingFrom: url)
            defer { try? handle.close() }
            try handle.seek(toOffset: UInt64(number) * UInt64(size))
            let data = try handle.read(upToCount: size) ?? Data()
            try data.write(to: destination, options: .atomic)
            return hex(SHA256.hash(data: data))
        }.value
    }

    nonisolated private static func readChunk(_ url: URL, number: Int, size: Int) async throws -> Data {
        try await Task.detached(priority: .userInitiated) {
            let handle = try FileHandle(forReadingFrom: url)
            defer { try? handle.close() }
            try handle.seek(toOffset: UInt64(number) * UInt64(size))
            return try handle.read(upToCount: size) ?? Data()
        }.value
    }
}
