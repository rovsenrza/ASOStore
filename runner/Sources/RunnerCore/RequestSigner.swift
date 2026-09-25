import CryptoKit
import Foundation

/// Signs worker API requests exactly as backend VerifyWorkerSignature checks them:
/// X-Signature = hex HMAC-SHA256(secret, METHOD\nPATH\nTIMESTAMP\nNONCE\nCONTENT_SHA256).
public struct RequestSigner: Sendable {
    public let keyID: String
    private let key: SymmetricKey

    public init(keyID: String, secret: String) {
        self.keyID = keyID
        self.key = SymmetricKey(data: Data(secret.utf8))
    }

    public func headers(method: String, path: String, contentSHA256: String, timestamp: Int = Int(Date().timeIntervalSince1970), nonce: String = RequestSigner.makeNonce()) -> [String: String] {
        [
            "X-Runner-Key": keyID,
            "X-Timestamp": String(timestamp),
            "X-Nonce": nonce,
            "X-Content-SHA256": contentSHA256,
            "X-Signature": signature(method: method, path: path, timestamp: String(timestamp), nonce: nonce, contentSHA256: contentSHA256),
        ]
    }

    public func signature(method: String, path: String, timestamp: String, nonce: String, contentSHA256: String) -> String {
        let canonical = [method.uppercased(), path, timestamp, nonce, contentSHA256].joined(separator: "\n")
        return HMAC<SHA256>.authenticationCode(for: Data(canonical.utf8), using: key).hex
    }

    public static func makeNonce() -> String {
        UUID().uuidString.replacingOccurrences(of: "-", with: "")
    }

    public static func sha256(_ data: Data) -> String {
        SHA256.hash(data: data).hex
    }

    /// Streams a file through SHA-256 without loading it into memory.
    public static func sha256(fileAt url: URL) throws -> String {
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }
        var hasher = SHA256()
        while let chunk = try handle.read(upToCount: 1 << 20), !chunk.isEmpty {
            hasher.update(data: chunk)
        }
        return hasher.finalize().hex
    }
}

extension Sequence where Element == UInt8 {
    var hex: String { map { String(format: "%02x", $0) }.joined() }
}
