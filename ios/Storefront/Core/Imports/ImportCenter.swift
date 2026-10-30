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
    private let uploader: BackgroundChunkUploader?
    /// Background uploads waiting for their last chunk, by server upload id.
    private var waiters: [String: CheckedContinuation<Void, any Error>] = [:]
    private var tasks: [UUID: Task<Void, Never>] = [:]
    private var sources: [UUID: Source] = [:]
    private var poll: Task<Void, Never>?
    /// Uploads stopped by something other than the customer (the app left the screen, the network
    /// dropped); they continue from the same chunk when the app is back in front.
    private var interrupted: Set<UUID> = []
    private var backgroundTasks: [UUID: UIBackgroundTaskIdentifier] = [:]
    private let log = Logger(subsystem: "storefront", category: "imports")

    /// Where an upload reads from, and what is left to send if it is retried.
    private struct Source {
        let url: URL
        let scoped: Bool
        /// A copy iOS put in Documents/Inbox for us; deleted once the upload is over.
        let ownsFile: Bool
        var session: ImportUploadDTO?
        var sent: Set<Int> = []
        /// Chunks were handed to the background session: they keep going while the app is away.
        var background = false
        var hashes: [Int: String] = [:]
        var failures: [Int: Int] = [:]
    }

    init(repository: ImportRepository, installations: InstallationCoordinator?, uploader: BackgroundChunkUploader? = nil) {
        self.repository = repository
        self.installations = installations
        self.uploader = uploader
        if let uploader {
            uploader.activate { [weak self] uploadID, number, outcome in
                Task { @MainActor in self?.chunkFinished(uploadID: uploadID, number: number, outcome: outcome) }
            }
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
        sources[upload.id] = Source(url: url, scoped: scoped, ownsFile: ownsFile)
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
        let importID = uploads.first { $0.id == uploadID }?.importID
        if let session = sources[uploadID]?.session, sources[uploadID]?.background == true {
            await uploader?.cancel(uploadID: session.id)
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
                let session = try await repository.startUpload(filename: file.name, sizeBytes: file.size, sha256: file.sha256)
                source.session = session
                source.sent = Set(session.receivedChunks)
                sources[id] = source
                update(id) { $0.importID = session.importId }
            }
            guard let session = source.session else { return }

            let sentBefore = source.sent.count
            update(id) {
                $0.phase = .uploading
                $0.progress = 0.05 + Double(sentBefore) / Double(max(session.chunkCount, 1)) * 0.93
            }
            if let token = session.uploadToken, try await uploadInBackground(id, session: session, token: token) {
                // Every chunk reached the server through the background session.
            } else {
                for number in 0..<session.chunkCount where sources[id]?.sent.contains(number) == false {
                    try Task.checkCancellation()
                    let chunk = try await Self.readChunk(source.url, number: number, size: session.chunkSize)
                    let digest = SHA256.hash(data: chunk).map { String(format: "%02x", $0) }.joined()
                    try await retrying { try await self.repository.putChunk(uploadID: session.id, number: number, data: chunk, sha256: digest) }
                    markSent(id, number: number)
                }
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

    /// A dropped connection or a busy server costs a wait, not the upload: it keeps retrying (at
    /// most every 30 s) for as long as the app is open. Giving up after two minutes made customers
    /// delete the import and start over from zero on a VPN that drops every few minutes.
    private func retrying<Value>(_ operation: () async throws -> Value) async throws -> Value {
        var attempt = 0
        while true {
            do {
                return try await operation()
            } catch let error as APIError where Self.isTransient(error) {
                attempt += 1
                try await Task.sleep(for: .seconds(min(1 << min(attempt, 5), 30)))
            }
        }
    }

    private func markSent(_ id: UUID, number: Int) {
        guard var source = sources[id], let session = source.session else { return }
        source.sent.insert(number)
        sources[id] = source
        let fraction = Double(source.sent.count) / Double(max(session.chunkCount, 1))
        update(id) { $0.progress = 0.05 + fraction * 0.93 }
    }

    // MARK: Background session

    /// Hands the chunks not yet on the server to the background session and waits until they all
    /// are. Returns false (nothing handed over) when there is no room to stage them as files.
    private func uploadInBackground(_ id: UUID, session: ImportUploadDTO, token: String) async throws -> Bool {
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
                let digest = try await Self.stageChunk(url, number: number, size: session.chunkSize, to: BackgroundChunkUploader.file(for: session.id, number: number))
                sources[id]?.hashes[number] = digest
                uploader.enqueue(await repository.backgroundChunkRequest(uploadID: session.id, number: number, token: token, sha256: digest), uploadID: session.id, number: number)
            }
        } else if !inFlight.isEmpty {
            sources[id]?.background = true
        }

        guard sources[id]?.background == true else { return false }
        try Task.checkCancellation()
        if (sources[id]?.sent.count ?? 0) < session.chunkCount {
            try await withTaskCancellationHandler {
                try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, any Error>) in
                    waiters.removeValue(forKey: session.id)?.resume(throwing: CancellationError())
                    if (sources[id]?.sent.count ?? 0) >= session.chunkCount {
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
        switch outcome {
        case .stored:
            markSent(id, number: number)
            if (sources[id]?.sent.count ?? 0) >= session.chunkCount {
                waiters.removeValue(forKey: uploadID)?.resume()
            }
        case .failed(transient: true, _):
            let failures = (sources[id]?.failures[number] ?? 0) + 1
            sources[id]?.failures[number] = failures
            Task { [weak self] in
                try? await Task.sleep(for: .seconds(min(1 << min(failures, 5), 30)))
                await self?.sendAgain(id, number: number)
            }
        case .failed:
            // Refused for good (the session closed, say): let complete ask the server what it still needs.
            waiters.removeValue(forKey: uploadID)?.resume()
        }
    }

    private func sendAgain(_ id: UUID, number: Int) async {
        guard let uploader, let source = sources[id], let session = source.session, let token = session.uploadToken,
              let digest = source.hashes[number], !source.sent.contains(number) else { return }
        uploader.enqueue(await repository.backgroundChunkRequest(uploadID: session.id, number: number, token: token, sha256: digest), uploadID: session.id, number: number)
    }

    nonisolated private static func hasRoom(for bytes: Int64) -> Bool {
        let values = try? URL.applicationSupportDirectory.resourceValues(forKeys: [.volumeAvailableCapacityForImportantUsageKey])
        guard let free = values?.volumeAvailableCapacityForImportantUsage else { return false }
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
        if let source = sources.removeValue(forKey: id) {
            if source.background, let session = source.session {
                try? FileManager.default.removeItem(at: BackgroundChunkUploader.directory(for: session.id))
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
            return SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
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
