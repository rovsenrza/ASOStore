import Foundation
#if canImport(CryptoKit)
import CryptoKit
#else
import COpenSSL
#endif

/// The hashes the runner needs: CryptoKit on macOS, the system's OpenSSL
/// libcrypto on Linux (already in the image for zsign), so there is no
/// third-party package to fetch.
public enum Digest {
    public enum Algorithm: Sendable { case sha1, sha256, sha384 }

    public static func hash(_ data: Data, _ algorithm: Algorithm) -> Data {
        var hasher = Hasher(algorithm)
        hasher.update(data)
        return hasher.finalize()
    }

    public static func hmacSHA256(key: Data, data: Data) -> Data {
        #if canImport(CryptoKit)
        Data(HMAC<SHA256>.authenticationCode(for: data, using: SymmetricKey(data: key)))
        #else
        var output = [UInt8](repeating: 0, count: Int(EVP_MAX_MD_SIZE))
        var length: UInt32 = 0
        key.withUnsafeBytes { keyBytes in
            data.withUnsafeBytes { dataBytes in
                _ = HMAC(EVP_sha256(), keyBytes.baseAddress, Int32(keyBytes.count), dataBytes.bindMemory(to: UInt8.self).baseAddress, dataBytes.count, &output, &length)
            }
        }
        return Data(output.prefix(Int(length)))
        #endif
    }

    /// Incremental hashing, for files too large to load at once.
    public struct Hasher {
        #if canImport(CryptoKit)
        private var state: State
        private enum State {
            case sha1(Insecure.SHA1), sha256(SHA256), sha384(SHA384)
        }

        public init(_ algorithm: Algorithm) {
            switch algorithm {
            case .sha1: state = .sha1(Insecure.SHA1())
            case .sha256: state = .sha256(SHA256())
            case .sha384: state = .sha384(SHA384())
            }
        }

        public mutating func update(_ data: Data) {
            switch state {
            case .sha1(var h): h.update(data: data); state = .sha1(h)
            case .sha256(var h): h.update(data: data); state = .sha256(h)
            case .sha384(var h): h.update(data: data); state = .sha384(h)
            }
        }

        public func finalize() -> Data {
            switch state {
            case .sha1(let h): Data(h.finalize())
            case .sha256(let h): Data(h.finalize())
            case .sha384(let h): Data(h.finalize())
            }
        }
        #else
        private final class Context {
            let pointer = EVP_MD_CTX_new()
            deinit { EVP_MD_CTX_free(pointer) }
        }
        private let context = Context()

        public init(_ algorithm: Algorithm) {
            let type = switch algorithm {
            case .sha1: EVP_sha1()
            case .sha256: EVP_sha256()
            case .sha384: EVP_sha384()
            }
            EVP_DigestInit_ex(context.pointer, type, nil)
        }

        public mutating func update(_ data: Data) {
            data.withUnsafeBytes { _ = EVP_DigestUpdate(context.pointer, $0.baseAddress, $0.count) }
        }

        /// Call once; the context is finished afterwards.
        public func finalize() -> Data {
            var output = [UInt8](repeating: 0, count: Int(EVP_MAX_MD_SIZE))
            var length: UInt32 = 0
            EVP_DigestFinal_ex(context.pointer, &output, &length)
            return Data(output.prefix(Int(length)))
        }
        #endif
    }
}
