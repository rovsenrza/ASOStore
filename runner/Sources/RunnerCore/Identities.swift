import Foundation

/// A code-signing identity in the runner's Keychain. Only metadata leaves the Mac.
public struct SigningIdentity: Codable, Equatable, Sendable {
    public var sha1: String
    public var serialNumber: String
    public var teamIdentifier: String
    public var commonName: String
    public var expiresAt: String?

    enum CodingKeys: String, CodingKey {
        case sha1, serialNumber = "serial_number", teamIdentifier = "team_identifier", commonName = "common_name", expiresAt = "expires_at"
    }
}

public enum Identities {
    /// Valid signing identities: `security find-identity -v -p codesigning`, then the
    /// certificate's subject (OU = team ID), serial and expiry via openssl.
    public static func load(keychain: String?) throws -> [SigningIdentity] {
        var arguments = ["find-identity", "-v", "-p", "codesigning"]
        if let keychain { arguments.append(keychain) }
        let listing = try Shell.run("/usr/bin/security", arguments).output

        return try parseFindIdentity(listing).compactMap { sha1, name in
            var certArguments = ["find-certificate", "-a", "-Z", "-p", "-c", name]
            if let keychain { certArguments.append(keychain) }
            let pems = try Shell.run("/usr/bin/security", certArguments).output
            guard let pem = pem(for: sha1, in: pems) else { return nil }

            let pemFile = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString + ".pem")
            try pem.write(to: pemFile, atomically: true, encoding: .utf8)
            defer { try? FileManager.default.removeItem(at: pemFile) }
            let details = try Shell.run("/usr/bin/openssl", ["x509", "-noout", "-subject", "-serial", "-enddate", "-nameopt", "RFC2253", "-in", pemFile.path]).output

            return parseCertificate(sha1: sha1, commonName: name, opensslOutput: details)
        }
    }

    /// Lines like `  1) 32456AA8… "Apple Distribution: Example (TEAMID)"`, skipping revoked ones.
    public static func parseFindIdentity(_ output: String) -> [(sha1: String, name: String)] {
        output.split(separator: "\n").compactMap { line in
            let text = String(line)
            guard !text.contains("CSSMERR"), !text.contains("REVOKED"),
                  let match = text.firstMatch(of: /\)\s+([0-9A-F]{40})\s+"(.+)"/) else { return nil }
            return (String(match.1), String(match.2))
        }
    }

    /// `security find-certificate -Z -p` prints "SHA-1 hash: …" before each PEM block.
    static func pem(for sha1: String, in output: String) -> String? {
        let blocks = output.components(separatedBy: "SHA-256 hash:").flatMap { $0.components(separatedBy: "SHA-1 hash:") }
        for (index, block) in blocks.enumerated() where block.trimmingCharacters(in: .whitespaces).uppercased().hasPrefix(sha1) {
            let rest = blocks[index...].joined(separator: "SHA-1 hash:")
            if let begin = rest.range(of: "-----BEGIN CERTIFICATE-----"), let end = rest.range(of: "-----END CERTIFICATE-----") {
                return String(rest[begin.lowerBound..<end.upperBound]) + "\n"
            }
        }
        return nil
    }

    public static func parseCertificate(sha1: String, commonName: String, opensslOutput: String) -> SigningIdentity? {
        var subject = "", serial = "", notAfter: String?
        for line in opensslOutput.split(separator: "\n").map(String.init) {
            if line.hasPrefix("subject=") { subject = String(line.dropFirst("subject=".count)) }
            if line.hasPrefix("serial=") { serial = String(line.dropFirst("serial=".count)) }
            if line.hasPrefix("notAfter=") { notAfter = String(line.dropFirst("notAfter=".count)) }
        }
        let team = subject.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }
            .first { $0.hasPrefix("OU=") }.map { String($0.dropFirst(3)) }
        guard let team, !serial.isEmpty else { return nil }

        return SigningIdentity(sha1: sha1, serialNumber: serial, teamIdentifier: team, commonName: commonName, expiresAt: notAfter.flatMap(iso8601(fromOpenSSL:)))
    }

    /// "Sep 25 12:00:00 2027 GMT" → ISO 8601.
    static func iso8601(fromOpenSSL value: String) -> String? {
        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        parser.timeZone = TimeZone(identifier: "GMT")
        parser.dateFormat = "MMM d HH:mm:ss yyyy zzz"
        let normalized = value.replacingOccurrences(of: "  ", with: " ")
        guard let date = parser.date(from: normalized) else { return nil }
        return ISO8601DateFormatter().string(from: date)
    }
}
