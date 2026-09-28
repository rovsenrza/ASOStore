import Foundation

/// A code-signing identity held by the runner. Only this metadata leaves the server.
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

/// An identity and the files zsign signs with.
public struct IdentityFiles: Equatable, Sendable {
    public var identity: SigningIdentity
    /// The certificate as Apple issued it (.cer, DER) or as PEM.
    public var certificate: URL
    /// The PEM private key the certificate request was made with.
    public var privateKey: URL
}

public enum Identities {
    /// Every `<name>.key` in the folder that has a matching `<name>.cer` or `<name>.pem`.
    /// A pair whose key does not belong to its certificate is skipped and logged,
    /// so the backend is never told about an identity the runner cannot sign with.
    public static func load(directory: URL) throws -> [IdentityFiles] {
        let files = try FileManager.default.contentsOfDirectory(at: directory, includingPropertiesForKeys: nil)
            .filter { !$0.lastPathComponent.hasPrefix(".") }
        let keys = files.filter { $0.pathExtension == "key" }

        return try keys.sorted { $0.lastPathComponent < $1.lastPathComponent }.compactMap { key in
            let name = key.deletingPathExtension().lastPathComponent
            guard let certificate = files.first(where: { ["cer", "pem"].contains($0.pathExtension) && $0.deletingPathExtension().lastPathComponent == name }) else {
                Log.error("identity \(name): no \(name).cer or \(name).pem next to the key")
                return nil
            }
            let form = try certificateForm(certificate)
            let certificateKey = try Shell.require("IDENTITY_INVALID", "openssl", ["x509", "-inform", form, "-in", certificate.path, "-noout", "-pubkey"]).output
            let privateKey = try Shell.require("IDENTITY_INVALID", "openssl", ["pkey", "-in", key.path, "-pubout"]).output
            guard certificateKey == privateKey else {
                Log.error("identity \(name): the key does not belong to the certificate")
                return nil
            }

            let details = try Shell.require("IDENTITY_INVALID", "openssl", [
                "x509", "-inform", form, "-in", certificate.path, "-noout",
                "-fingerprint", "-sha1", "-serial", "-enddate", "-subject", "-nameopt", "multiline,utf8,-esc_msb",
            ]).output
            guard let identity = parseCertificate(opensslOutput: details) else {
                Log.error("identity \(name): certificate has no team (OU) or serial")
                return nil
            }
            return IdentityFiles(identity: identity, certificate: certificate, privateKey: key)
        }
    }

    /// "DER" for certificates as Apple's portal downloads them, "PEM" for text ones.
    static func certificateForm(_ url: URL) throws -> String {
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }
        let start = try handle.read(upToCount: 11) ?? Data()
        return start == Data("-----BEGIN ".utf8) ? "PEM" : "DER"
    }

    /// Parses `openssl x509 -fingerprint -sha1 -serial -enddate -subject -nameopt multiline`.
    /// The team is the subject's OU; the name shown in the admin is the CN.
    public static func parseCertificate(opensslOutput: String) -> SigningIdentity? {
        var sha1 = "", serial = "", notAfter: String?, subject: [String: String] = [:]
        for line in opensslOutput.split(separator: "\n").map(String.init) {
            if line.lowercased().hasPrefix("sha1 fingerprint=") {
                sha1 = line.split(separator: "=", maxSplits: 1).last.map { $0.replacingOccurrences(of: ":", with: "").uppercased() } ?? ""
            } else if line.hasPrefix("serial=") {
                serial = String(line.dropFirst("serial=".count))
            } else if line.hasPrefix("notAfter=") {
                notAfter = String(line.dropFirst("notAfter=".count))
            } else if line.first?.isWhitespace == true, let equals = line.range(of: " = ") {
                subject[line[..<equals.lowerBound].trimmingCharacters(in: .whitespaces)] = String(line[equals.upperBound...])
            }
        }
        guard sha1.count == 40, !serial.isEmpty, let team = subject["organizationalUnitName"], !team.isEmpty else { return nil }

        return SigningIdentity(sha1: sha1, serialNumber: serial, teamIdentifier: team, commonName: subject["commonName"] ?? team, expiresAt: notAfter.flatMap(iso8601(fromOpenSSL:)))
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
