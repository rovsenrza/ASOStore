import Foundation

// Keys are decoded with APIClient's convertFromSnakeCase strategy.

/// GET /imports items, POST /imports/link: one IPA the customer imported for themselves.
nonisolated struct ImportDTO: Codable, Hashable, Sendable, Identifiable {
    let id: String
    let name: String
    /// An artifact status once the file is stored, else DOWNLOADING / DOWNLOAD_FAILED (link)
    /// or UPLOADING / UPLOAD_FAILED (Files).
    let status: String?
    let version: String?
    let sizeBytes: Int64?
    /// Inspected and ready for its first install, or already published for this customer.
    let installable: Bool
    let failureReason: String?
    let createdAt: String?

    /// Still being downloaded, stored or inspected on the server: worth polling.
    var isProcessing: Bool {
        ["DOWNLOADING", "UPLOADED", "HASHING", "INSPECTING", "COMPATIBILITY_CHECK"].contains(status ?? "")
    }

    /// An upload from Files that is not running on this device (the app was closed mid-upload):
    /// picking the same file again continues it on the server.
    var isUnfinishedUpload: Bool {
        status == "UPLOADING"
    }

    var isFailed: Bool {
        ["DOWNLOAD_FAILED", "UPLOAD_FAILED", "INSPECTION_FAILED", "PROVENANCE_FAILED", "QUARANTINED", "REJECTED", "REVOKED", "EXPIRED"]
            .contains(status ?? "")
    }
}

/// POST /imports: an open chunked upload.
nonisolated struct ImportUploadDTO: Codable, Hashable, Sendable {
    let id: String
    let importId: String
    let status: String
    let chunkSize: Int
    let chunkCount: Int
    let receivedChunks: [Int]
    let missingChunks: [Int]
}

/// PUT /imports/{upload}/chunks/{n}.
nonisolated struct ImportChunkDTO: Codable, Hashable, Sendable {
    let number: Int
    let alreadyReceived: Bool
}

/// POST /imports/{upload}/complete.
nonisolated struct ImportCompleteDTO: Codable, Hashable, Sendable {
    let importId: String
    let status: String
}

nonisolated struct ImportDeletedDTO: Codable, Hashable, Sendable {
    let deleted: Bool
}
