import Foundation
import OSLog
import Security

/// Minimal generic-password Keychain wrapper. Items are readable after the
/// first unlock and never leave this device (not synced, not in backups).
nonisolated struct KeychainStore: Sendable {
    let service: String

    func data(for account: String) -> Data? {
        var query = baseQuery(account)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: AnyObject?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess else { return nil }
        return result as? Data
    }

    @discardableResult
    func set(_ data: Data, for account: String) -> Bool {
        let attributes: [String: Any] = [
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
        ]
        var status = SecItemUpdate(baseQuery(account) as CFDictionary, attributes as CFDictionary)
        var operation = "update"
        if status == errSecItemNotFound {
            status = SecItemAdd(baseQuery(account).merging(attributes) { $1 } as CFDictionary, nil)
            operation = "add"
        }
        if status != errSecSuccess {
            Self.log.error("keychain \(operation, privacy: .public) failed for \(service, privacy: .public)/\(account, privacy: .private): OSStatus \(status, privacy: .public)")
        }
        return status == errSecSuccess
    }

    private static let log = Logger(subsystem: "storefront", category: "keychain")

    func remove(_ account: String) {
        SecItemDelete(baseQuery(account) as CFDictionary)
    }

    private func baseQuery(_ account: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
    }
}
