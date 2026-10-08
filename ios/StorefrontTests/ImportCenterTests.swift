import CryptoKit
import Foundation
import Testing
@testable import Storefront

@Suite("Imports")
@MainActor
struct ImportCenterTests {
    @Test func importsFixtureDecodes() async throws {
        let imports = try await APIClient.stubbed(StubTransport(body: Fixtures.data("imports-list"))).get("/imports", as: [ImportDTO].self).data

        #expect(imports.map(\.status) == ["DOWNLOADING", "PROVENANCE_REVIEW", "DOWNLOAD_FAILED"])
        #expect(imports[0].isProcessing)
        #expect(imports[1].installable)
        #expect(imports[2].isFailed)
        #expect(ImportCenter.failureText(for: imports[2]) == "По ссылке не файл IPA. Нужна прямая ссылка на скачивание.")
    }

    @Test func sendsChunksAsRawBytesWithTheirHash() async throws {
        let transport = StubTransport(body: Data(#"{"data":{"number":0,"size_bytes":3,"sha256":"x","already_received":false},"meta":{"request_id":"r"},"error":null}"#.utf8))
        try await ImportRepository(api: .stubbed(transport)).putChunk(uploadID: "u1", number: 0, data: Data("abc".utf8), sha256: "deadbeef")

        let request = try #require(transport.lastRequest)
        #expect(request.httpMethod == "PUT")
        #expect(request.url?.path() == "/api/v1/imports/u1/chunks/0")
        #expect(request.value(forHTTPHeaderField: "Content-Type") == "application/octet-stream")
        #expect(request.value(forHTTPHeaderField: "X-Chunk-SHA256") == "deadbeef")
        #expect(request.httpBody == Data("abc".utf8))
    }

    @Test func uploadsAFileInChunksThenCompletes() async throws {
        let bytes = Data("PK\u{3}\u{4}0123456789".utf8) // 14 bytes → chunks of 5, 5, 4
        let file = URL.temporaryDirectory.appending(path: "Test App.ipa")
        try bytes.write(to: file)
        let digest = SHA256.hash(data: bytes).map { String(format: "%02x", $0) }.joined()

        let chunks = ChunkLog()
        let transport = ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("POST", "/api/v1/imports"):
                let body = try JSONSerialization.jsonObject(with: request.httpBody ?? Data()) as? [String: Any]
                #expect(body?["filename"] as? String == "Test App.ipa")
                #expect(body?["size_bytes"] as? Int == 14)
                #expect(body?["sha256"] as? String == digest)
                #expect(body?["declaration_accepted"] as? Bool == true)
                return (201, Data(#"{"data":{"id":"up1","import_id":"imp1","status":"OPEN","chunk_size":5,"chunk_count":3,"received_chunks":[],"missing_chunks":[0,1,2],"expires_at":"2030-01-01T00:00:00Z"},"meta":{"request_id":"r"},"error":null}"#.utf8))
            case ("PUT", _):
                chunks.append(path, request.httpBody ?? Data())
                return (200, Data(#"{"data":{"number":0,"size_bytes":5,"sha256":"x","already_received":false},"meta":{"request_id":"r"},"error":null}"#.utf8))
            case ("POST", "/api/v1/imports/up1/complete"):
                return (201, Data(#"{"data":{"import_id":"imp1","status":"PROVENANCE_REVIEW","job_id":null,"sha256":"x","size_bytes":14},"meta":{"request_id":"r"},"error":null}"#.utf8))
            case ("GET", "/api/v1/imports"):
                return (200, Data(#"{"data":[{"id":"imp1","name":"Test App","status":"PROVENANCE_REVIEW","version":"1.0","size_bytes":14,"installable":true,"failure_reason":null,"created_at":null}],"meta":{"request_id":"r"},"error":null}"#.utf8))
            default:
                return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil)

        center.importFile(at: file)
        #expect(center.uploads.count == 1)
        for _ in 0..<200 where !center.uploads.isEmpty {
            try await Task.sleep(for: .milliseconds(20))
        }

        #expect(center.uploads.isEmpty)
        #expect(chunks.paths == ["/api/v1/imports/up1/chunks/0", "/api/v1/imports/up1/chunks/1", "/api/v1/imports/up1/chunks/2"])
        #expect(chunks.joined == bytes)
        #expect(center.visibleImports.map(\.id) == ["imp1"])
        #expect(center.visibleImports.first?.installable == true)
    }

    /// Picking a file whose upload the server already has in part sends only what is missing,
    /// and a connection dropped mid-chunk costs a retry of that chunk, not the upload.
    @Test func continuesAnUploadAndRidesOutADroppedConnection() async throws {
        let bytes = Data("PK\u{3}\u{4}0123456789".utf8) // 14 bytes → chunks of 5, 5, 4
        let file = URL.temporaryDirectory.appending(path: "Resume App.ipa")
        try bytes.write(to: file)

        let chunks = ChunkLog()
        let dropOnce = DropOnce()
        let transport = ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            switch (request.httpMethod, path) {
            case ("POST", "/api/v1/imports"):
                // The server kept chunk 0 from an earlier attempt.
                return (201, Data(#"{"data":{"id":"up2","import_id":"imp2","status":"OPEN","chunk_size":5,"chunk_count":3,"received_chunks":[0],"missing_chunks":[1,2],"expires_at":"2030-01-01T00:00:00Z"},"meta":{"request_id":"r"},"error":null}"#.utf8))
            case ("PUT", _):
                if path.hasSuffix("/chunks/1"), dropOnce.first() {
                    throw URLError(.networkConnectionLost)
                }
                chunks.append(path, request.httpBody ?? Data())
                return (200, Data(#"{"data":{"number":0,"size_bytes":5,"sha256":"x","already_received":false},"meta":{"request_id":"r"},"error":null}"#.utf8))
            case ("POST", "/api/v1/imports/up2/complete"):
                return (201, Data(#"{"data":{"import_id":"imp2","status":"PROVENANCE_REVIEW","job_id":null,"sha256":"x","size_bytes":14},"meta":{"request_id":"r"},"error":null}"#.utf8))
            case ("GET", "/api/v1/imports"):
                return (200, Data(#"{"data":[],"meta":{"request_id":"r"},"error":null}"#.utf8))
            default:
                return (404, Envelopes.error("NOT_FOUND"))
            }
        }
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(transport)), installations: nil)

        center.importFile(at: file)
        for _ in 0..<400 where !center.uploads.isEmpty {
            try await Task.sleep(for: .milliseconds(20))
        }

        #expect(center.uploads.isEmpty)
        #expect(chunks.paths == ["/api/v1/imports/up2/chunks/1", "/api/v1/imports/up2/chunks/2"])
        #expect(chunks.joined == bytes.dropFirst(5))
    }

    @Test func refusesALinkThatIsNotHTTPS() async {
        let center = ImportCenter(repository: ImportRepository(api: .stubbed(StubTransport(body: Data()))), installations: nil)

        #expect(await center.importLink("http://example.com/a.ipa") == false)
        #expect(center.message == "Нужна ссылка, начинающаяся с https://.")
    }
}

nonisolated private final class DropOnce: @unchecked Sendable {
    private let lock = NSLock()
    private var dropped = false

    /// True the first time only.
    func first() -> Bool {
        lock.withLock {
            defer { dropped = true }
            return !dropped
        }
    }
}

nonisolated private final class ChunkLog: @unchecked Sendable {
    private let lock = NSLock()
    private var entries: [(String, Data)] = []

    func append(_ path: String, _ data: Data) {
        lock.withLock { entries.append((path, data)) }
    }

    var paths: [String] { lock.withLock { entries.map(\.0) } }
    var joined: Data { lock.withLock { entries.reduce(into: Data()) { $0.append($1.1) } } }
}
