import Foundation

/// Last successful catalog responses, so the catalog still renders offline
/// (FULL_PLAN §11 "cached fallback"). Stored in Caches: iOS may purge it.
nonisolated struct ResponseCache: Sendable {
    let directory: URL

    static let catalog = ResponseCache(
        directory: FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0].appending(path: "catalog", directoryHint: .isDirectory)
    )

    func store(_ value: some Encodable, key: String) {
        guard let data = try? JSONEncoder().encode(value) else { return }
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        try? data.write(to: url(for: key), options: .atomic)
    }

    func load<Value: Decodable>(_ type: Value.Type, key: String) -> Value? {
        guard let data = try? Data(contentsOf: url(for: key)) else { return nil }
        return try? JSONDecoder().decode(type, from: data)
    }

    func clear() {
        try? FileManager.default.removeItem(at: directory)
    }

    private func url(for key: String) -> URL {
        directory.appending(path: key.replacingOccurrences(of: "/", with: "_") + ".json")
    }
}
