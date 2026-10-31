import CryptoKit
import Foundation
import Testing
@testable import Storefront

/// Uploads that survive a relaunch, background events that arrive early, token refresh, and
/// bounded retries.
@Suite("Import upload reliability")
@MainActor
struct ImportUploadReliabilityTests {
    nonisolated private static let bytes = Data("PK\u{3}\u{4}0123456789".utf8) // 14 bytes → chunks of 5, 5, 4
    nonisolated private static let fast = ImportRetryPolicy(maxRetries: 3, baseDelay: .milliseconds(10), maxDelay: .milliseconds(20))

    nonisolated private static func hex(_ data: Data) -> String {
        SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
    }

    nonisolated private static func tempDirectory() throws -> URL {
        let url = URL.temporaryDirectory.appending(path: "imports-\(UUID().uuidString)", directoryHint: .isDirectory)
        try FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        return url
    }

    nonisolated private static func writeFile(_ name: String) throws -> URL {
        let file = try tempDirectory().appending(path: name)
        try bytes.write(to: file)
        return file
    }

    nonisolated private static func ok(_ json: String, status: Int = 200) -> (Int, Data) {
        (status, Data(#"{"data":\#(json),"meta":{"request_id":"r"},"error":null}"#.utf8))
    }

    nonisolated private static func upload(_ id: String, received: [Int], token: String? = nil, expires: String? = nil) -> (Int, Data) {
        var extra = ""
        if let token { extra += #","upload_token":"\#(token)""# }
        if let expires { extra += #","upload_token_expires_at":"\#(expires)""# }
        let receivedList = received.map(String.init).joined(separator: ",")
        return ok(#"{"id":"\#(id)","import_id":"imp-\#(id)","status":"OPEN","chunk_size":5,"chunk_count":3,"received_chunks":[\#(receivedList)],"missing_chunks":[],"expires_at":"2030-01-01T00:00:00Z"\#(extra)}"#, status: 201)
    }

    nonisolated private static func uploadingRow(_ importID: String) -> (Int, Data) {
        ok(#"[{"id":"\#(importID)","name":"App","status":"UPLOADING","version":null,"size_bytes":14,"installable":false,"failure_reason":null,"created_at":null}]"#)
    }

    nonisolated private static let completed = ok(#"{"import_id":"imp","status":"PROVENANCE_REVIEW","job_id":null,"sha256":"x","size_bytes":14}"#, status: 201)
    nonisolated private static let chunkStored = ok(#"{"number":0,"size_bytes":5,"sha256":"x","already_received":false}"#)

    nonisolated private static func record(_ uploadID: String, file: URL, sent: [Int], background: Bool = false, token: String? = nil) -> PersistedImportUpload {
        PersistedImportUpload(
            id: UUID(), name: "App", filename: "App.ipa", sizeBytes: 14, sha256: hex(bytes),
            uploadID: uploadID, importID: "imp-\(uploadID)", uploadToken: token, uploadTokenExpiresAt: nil,
            chunkSize: 5, chunkCount: 3, chunkHashes: [:], sentChunks: sent, background: background,
            bookmark: nil, stagedPath: file.path, ownsFile: false
        )
    }

    private func settle(_ center: ImportCenter, iterations: Int = 400) async throws {
        for _ in 0..<iterations where !center.uploads.isEmpty {
            try await Task.sleep(for: .milliseconds(20))
        }
    }

    // MARK: Persistence

    @Test func savedUploadsRoundTrip() throws {
        let store = ImportUploadStore(directory: try Self.tempDirectory())
        var saved = Self.record("up1", file: URL(fileURLWithPath: "/tmp/x.ipa"), sent: [0, 2], token: "tok")
        saved.chunkHashes = [1: "abc"]
        saved.uploadTokenExpiresAt = "2030-01-01T00:00:00Z"
        saved.bookmark = Data([1, 2, 3])

        store.upsert(saved)
        #expect(store.load() == [saved])

        saved.sentChunks = [0, 1, 2]
        store.upsert(saved)
        #expect(store.load() == [saved])
        #expect(ImportUploadStore(directory: store.directory).load() == [saved])

        store.remove(saved.id)
        #expect(store.load().isEmpty)
    }

    /// After a relaunch the upload is rebuilt from disk, the server is asked what it already has,
    /// and only the rest is sent.
    @Test func restoresASavedUploadAndSendsOnlyWhatTheServerLacks() async throws {
        let file = try Self.writeFile("Saved App.ipa")
        let store = ImportUploadStore(directory: try Self.tempDirectory())
        store.upsert(Self.record("up3", file: file, sent: [0]))

        let chunks = RequestLog()
        let transport = ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("GET", "/api/v1/imports"): return Self.uploadingRow("imp-up3")
            // The server got chunk 1 too while the app was closed.
            case ("POST", "/api/v1/imports"): return Self.upload("up3", received: [0, 1])
            case ("PUT", _):
                chunks.append(request)
                return Self.chunkStored
            case ("POST", "/api/v1/imports/up3/complete"): return Self.completed
            default: return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil, store: store, retryPolicy: Self.fast)

        #expect(center.uploads.map(\.importID) == ["imp-up3"])
        try await settle(center)

        #expect(center.uploads.isEmpty)
        #expect(chunks.paths == ["/api/v1/imports/up3/chunks/2"])
        #expect(chunks.bodies == [Self.bytes.suffix(4)])
        #expect(store.load().isEmpty)
    }

    // MARK: Background events

    @Test func backgroundResultsAreBufferedUntilAHandlerAttaches() async {
        let uploader = BackgroundChunkUploader(identifier: "test.\(UUID().uuidString)")
        uploader.deliver(uploadID: "u", number: 0, outcome: .stored)
        uploader.deliver(uploadID: "u", number: 1, outcome: .failed(transient: true, status: 503))
        #expect(uploader.bufferedCount == 2)

        let received = EventLog()
        uploader.attach { uploadID, number, outcome in received.append("\(uploadID)/\(number)/\(outcome)") }
        #expect(uploader.bufferedCount == 0)
        #expect(received.items == ["u/0/stored", "u/1/failed(transient: true, status: 503)"])

        uploader.deliver(uploadID: "u", number: 2, outcome: .stored)
        #expect(received.items.count == 3)

        // Another session's events are not ours: iOS is told at once.
        var completed = false
        uploader.handleEvents(for: "someone.else") { completed = true }
        #expect(completed)
    }

    // MARK: Upload token

    @Test func refreshesTheUploadTokenOnUnauthorizedAndResends() async throws {
        let file = try Self.writeFile("Token App.ipa")
        let refreshes = RequestLog()
        let foreground = RequestLog()
        let transport = ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("POST", "/api/v1/imports"): return Self.upload("up4", received: [], token: "t1", expires: "2030-01-01T00:00:00Z")
            case ("POST", "/api/v1/imports/up4/token"):
                refreshes.append(request)
                return (200, Self.upload("up4", received: [], token: "t2", expires: "2030-01-02T00:00:00Z").1)
            case ("PUT", _):
                foreground.append(request)
                return Self.chunkStored
            case ("POST", "/api/v1/imports/up4/complete"): return Self.completed
            case ("GET", "/api/v1/imports"): return Self.ok("[]")
            default: return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let uploader = FakeChunkUploader { request, _ in
            request.value(forHTTPHeaderField: "X-Upload-Token") == "t2" ? .stored : .failed(transient: false, status: 401)
        }
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil, uploader: uploader, retryPolicy: Self.fast)

        center.importFile(at: file)
        try await settle(center)

        #expect(center.uploads.isEmpty)
        #expect(refreshes.paths.count >= 1)
        #expect(foreground.paths.isEmpty)
        #expect(Set(uploader.stored) == [0, 1, 2])
        #expect(uploader.enqueued.filter { $0.token == "t2" }.map(\.number).sorted() == [0, 1, 2])
    }

    /// A server without the refresh endpoint: the chunks the token could not send go from the
    /// open app, and complete is only called once the server has every chunk.
    @Test func fallsBackToTheAppWhenTheTokenCannotBeRefreshed() async throws {
        let file = try Self.writeFile("Old Server App.ipa")
        let calls = RequestLog()
        let transport = ScriptedTransport { request in
            calls.append(request)
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("POST", "/api/v1/imports"): return Self.upload("up6", received: [], token: "t1")
            case ("PUT", _): return Self.chunkStored
            case ("POST", "/api/v1/imports/up6/complete"): return Self.completed
            case ("GET", "/api/v1/imports"): return Self.ok("[]")
            default: return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let uploader = FakeChunkUploader { _, number in number == 1 ? .failed(transient: false, status: 403) : .stored }
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil, uploader: uploader, retryPolicy: Self.fast)

        center.importFile(at: file)
        try await settle(center)

        #expect(center.uploads.isEmpty)
        let paths = calls.paths
        let completeAt = try #require(paths.firstIndex(of: "/api/v1/imports/up6/complete"))
        let chunk1At = try #require(paths.firstIndex(of: "/api/v1/imports/up6/chunks/1"))
        #expect(chunk1At < completeAt)
        #expect(paths.contains("/api/v1/imports/up6/token"))
    }

    /// 410 from the token endpoint: the session expired, so the upload fails without complete.
    @Test func stopsWhenTheServerSaysTheUploadIsClosed() async throws {
        let file = try Self.writeFile("Expired App.ipa")
        let calls = RequestLog()
        let transport = ScriptedTransport { request in
            calls.append(request)
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("POST", "/api/v1/imports"): return Self.upload("up8", received: [], token: "t1")
            case ("POST", "/api/v1/imports/up8/token"): return (410, Envelopes.error("UPLOAD_EXPIRED"))
            default: return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let uploader = FakeChunkUploader { _, _ in .failed(transient: false, status: 401) }
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil, uploader: uploader, retryPolicy: Self.fast)

        center.importFile(at: file)
        for _ in 0..<200 {
            if case .failed = center.uploads.first?.phase { break }
            try await Task.sleep(for: .milliseconds(20))
        }

        guard case .failed = center.uploads.first?.phase else {
            Issue.record("upload did not fail: \(String(describing: center.uploads.first?.phase))")
            return
        }
        #expect(!calls.paths.contains("/api/v1/imports/up8/complete"))
        #expect(!calls.paths.contains { $0.hasPrefix("/api/v1/imports/up8/chunks") })
    }

    // MARK: Missing hash

    /// A chunk that was in flight before the relaunch has no hash in this launch; when it fails,
    /// the hash is computed again from the file and the chunk is sent again.
    @Test func recomputesAMissingChunkHashBeforeSendingAgain() async throws {
        let file = try Self.writeFile("Hash App.ipa")
        let store = ImportUploadStore(directory: try Self.tempDirectory())
        store.upsert(Self.record("up5", file: file, sent: [0, 1], background: true, token: "t1"))
        try? FileManager.default.removeItem(at: BackgroundChunkUploader.directory(for: "up5"))

        let transport = ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("GET", "/api/v1/imports"): return Self.uploadingRow("imp-up5")
            case ("POST", "/api/v1/imports"): return Self.upload("up5", received: [0, 1])
            case ("POST", "/api/v1/imports/up5/complete"): return Self.completed
            default: return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let uploader = FakeChunkUploader { _, _ in .stored }
        uploader.inFlight = [2]
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil, uploader: uploader, store: store, retryPolicy: Self.fast)

        for _ in 0..<200 where !uploader.pendingQueried {
            try await Task.sleep(for: .milliseconds(10))
        }
        uploader.inFlight = []
        uploader.fire(uploadID: "up5", number: 2, outcome: .failed(transient: true, status: 503))
        try await settle(center)

        #expect(center.uploads.isEmpty)
        let resent = try #require(uploader.enqueued.first { $0.number == 2 })
        #expect(resent.sha256 == Self.hex(Self.bytes.suffix(4)))
    }

    // MARK: Retry limit

    @Test func stopsRetryingAfterTheLimit() async throws {
        let file = try Self.writeFile("Flaky App.ipa")
        let puts = RequestLog()
        let transport = ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("POST", "/api/v1/imports"): return Self.upload("up7", received: [])
            case ("PUT", _):
                puts.append(request)
                throw URLError(.timedOut)
            default: return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let policy = ImportRetryPolicy(maxRetries: 2, baseDelay: .milliseconds(5), maxDelay: .milliseconds(10))
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil, retryPolicy: policy)

        center.importFile(at: file)
        for _ in 0..<200 {
            if case .failed = center.uploads.first?.phase { break }
            try await Task.sleep(for: .milliseconds(20))
        }

        guard case .failed = center.uploads.first?.phase else {
            Issue.record("upload did not stop: \(String(describing: center.uploads.first?.phase))")
            return
        }
        #expect(puts.paths == Array(repeating: "/api/v1/imports/up7/chunks/0", count: 3))
        await center.cancel(center.uploads[0].id)
    }

    @Test func backoffHasJitterWithinBounds() {
        let policy = ImportRetryPolicy(maxRetries: 5, baseDelay: .seconds(1), maxDelay: .seconds(30))
        let delays = (0..<50).map { _ in policy.delay(forAttempt: 3) }
        #expect(delays.allSatisfy { $0 >= .seconds(2) && $0 <= .seconds(4) })
        #expect(Set(delays).count > 1)
        #expect(policy.delay(forAttempt: 20) <= .seconds(30))
    }

    @Test func decodesTheTokenExpiry() async throws {
        let dto = try await APIClient.stubbed(StubTransport(body: Self.upload("u", received: [], token: "t", expires: "2030-01-01T00:00:00Z").1))
            .post("/imports", as: ImportUploadDTO.self).data
        #expect(dto.uploadToken == "t")
        #expect(dto.uploadTokenExpiry == ISO8601DateFormatter().date(from: "2030-01-01T00:00:00Z"))
        let older = try await APIClient.stubbed(StubTransport(body: Self.upload("u", received: []).1))
            .post("/imports", as: ImportUploadDTO.self).data
        #expect(older.uploadTokenExpiry == nil)
    }
}

// MARK: Fakes

/// A background session that answers each enqueued chunk with `respond`.
nonisolated final class FakeChunkUploader: ChunkUploading, @unchecked Sendable {
    struct Enqueued: Sendable {
        let number: Int
        let token: String?
        let sha256: String?
    }

    private let lock = NSLock()
    private let respond: @Sendable (URLRequest, Int) -> BackgroundChunkUploader.Outcome
    private var handler: Handler?
    private var _enqueued: [Enqueued] = []
    private var _stored: [Int] = []
    private var _inFlight: Set<Int> = []
    private var _pendingQueried = false

    init(respond: @escaping @Sendable (URLRequest, Int) -> BackgroundChunkUploader.Outcome) {
        self.respond = respond
    }

    var enqueued: [Enqueued] { lock.withLock { _enqueued } }
    var stored: [Int] { lock.withLock { _stored } }
    var pendingQueried: Bool { lock.withLock { _pendingQueried } }
    var inFlight: Set<Int> {
        get { lock.withLock { _inFlight } }
        set { lock.withLock { _inFlight = newValue } }
    }

    func activate(handler: @escaping Handler) {
        lock.withLock { self.handler = handler }
    }

    func enqueue(_ request: URLRequest, uploadID: String, number: Int) {
        let outcome = respond(request, number)
        lock.withLock {
            _enqueued.append(Enqueued(number: number, token: request.value(forHTTPHeaderField: "X-Upload-Token"), sha256: request.value(forHTTPHeaderField: "X-Chunk-SHA256")))
            if outcome == .stored { _stored.append(number) }
        }
        DispatchQueue.global().asyncAfter(deadline: .now() + 0.01) { [weak self] in
            self?.fire(uploadID: uploadID, number: number, outcome: outcome)
        }
    }

    func fire(uploadID: String, number: Int, outcome: BackgroundChunkUploader.Outcome) {
        lock.withLock { handler }?(uploadID, number, outcome)
    }

    func pending(uploadID: String) async -> Set<Int> {
        lock.withLock {
            _pendingQueried = true
            return _inFlight
        }
    }

    func activeUploads() async -> Set<String> { [] }
    func cancel(uploadID: String) async {}
}

nonisolated private final class RequestLog: @unchecked Sendable {
    private let lock = NSLock()
    private var requests: [URLRequest] = []

    func append(_ request: URLRequest) {
        lock.withLock { requests.append(request) }
    }

    var paths: [String] { lock.withLock { requests.map { $0.url?.path() ?? "" } } }
    var bodies: [Data] { lock.withLock { requests.map { $0.httpBody ?? Data() } } }
}

nonisolated private final class EventLog: @unchecked Sendable {
    private let lock = NSLock()
    private var entries: [String] = []

    func append(_ item: String) {
        lock.withLock { entries.append(item) }
    }

    var items: [String] { lock.withLock { entries } }
}
