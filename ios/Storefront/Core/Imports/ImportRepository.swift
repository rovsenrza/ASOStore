import Foundation

/// Customer self-import calls: an IPA from Files (chunked upload) or from a link.
nonisolated struct ImportRepository: Sendable {
    let api: APIClient

    private struct StartUpload: Encodable, Sendable {
        let filename: String
        let sizeBytes: Int64
        let sha256: String
        let declarationAccepted = true
    }

    private struct StartLink: Encodable, Sendable {
        let url: String
        let declarationAccepted = true
    }

    func list() async throws -> [ImportDTO] {
        try await api.get("/imports", as: [ImportDTO].self).data
    }

    func startUpload(filename: String, sizeBytes: Int64, sha256: String) async throws -> ImportUploadDTO {
        try await api.post("/imports", body: StartUpload(filename: filename, sizeBytes: sizeBytes, sha256: sha256), as: ImportUploadDTO.self).data
    }

    func putChunk(uploadID: String, number: Int, data: Data, sha256: String) async throws {
        _ = try await api.put("/imports/\(uploadID)/chunks/\(number)", data: data, headers: ["X-Chunk-SHA256": sha256], as: ImportChunkDTO.self)
    }

    func complete(uploadID: String) async throws -> ImportCompleteDTO {
        try await api.post("/imports/\(uploadID)/complete", as: ImportCompleteDTO.self).data
    }

    func startLink(_ url: String) async throws -> ImportDTO {
        try await api.post("/imports/link", body: StartLink(url: url), as: ImportDTO.self).data
    }

    /// Approves the owner's inspected import and prepares it for this device.
    func install(importID: String) async throws -> InstallationDTO {
        try await api.post("/imports/\(importID)/install", as: InstallationDTO.self).data
    }

    func delete(importID: String) async throws {
        _ = try await api.delete("/imports/\(importID)", as: ImportDeletedDTO.self)
    }
}
