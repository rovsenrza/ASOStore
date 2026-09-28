import Foundation

/// Plain line logging to stderr, which `docker logs` and journald collect.
/// Never log keys, secrets, profile contents or device identifiers.
public enum Log {
    public static func info(_ message: String) { write("INFO", message) }
    public static func error(_ message: String) { write("ERROR", message) }

    private static func write(_ level: String, _ message: String) {
        let line = "\(Date().formatted(.iso8601)) \(level) storefront-runner: \(message)\n"
        FileHandle.standardError.write(Data(line.utf8))
    }
}
