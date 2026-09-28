import Foundation

/// Runs a command line tool without a shell (no string interpolation into
/// /bin/sh), capturing its output.
public enum Shell {
    public struct Result: Sendable {
        public let status: Int32
        public let data: Data
        public let error: String

        public var output: String { String(decoding: data, as: UTF8.self) }
    }

    @discardableResult
    public static func run(_ executable: String, _ arguments: [String], in directory: URL? = nil) throws -> Result {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: try locate(executable))
        process.arguments = arguments
        process.currentDirectoryURL = directory
        let out = Pipe(), err = Pipe()
        process.standardOutput = out
        process.standardError = err
        try process.run()
        // Drain stderr in the background while reading stdout, so neither pipe can fill up and block.
        let errorBox = ErrorBox()
        let errorRead = DispatchSemaphore(value: 0)
        DispatchQueue.global().async {
            errorBox.data = err.fileHandleForReading.readDataToEndOfFile()
            errorRead.signal()
        }
        let outData = out.fileHandleForReading.readDataToEndOfFile()
        process.waitUntilExit()
        errorRead.wait()

        return Result(status: process.terminationStatus, data: outData, error: String(decoding: errorBox.data, as: UTF8.self))
    }

    /// Like run, but a non-zero exit becomes a job error with the given code.
    @discardableResult
    public static func require(_ code: String, _ executable: String, _ arguments: [String], in directory: URL? = nil) throws -> Result {
        let result = try run(executable, arguments, in: directory)
        guard result.status == 0 else {
            throw RunnerError.job(code: code, message: (result.error.isEmpty ? result.output : result.error).trimmingCharacters(in: .whitespacesAndNewlines), retryable: false)
        }
        return result
    }

    /// An absolute path is used as is; a bare name is looked up in PATH.
    public static func locate(_ executable: String) throws -> String {
        if executable.contains("/") { return executable }
        let path = ProcessInfo.processInfo.environment["PATH"] ?? "/usr/local/bin:/usr/bin:/bin"
        for directory in path.split(separator: ":") {
            let candidate = "\(directory)/\(executable)"
            if FileManager.default.isExecutableFile(atPath: candidate) { return candidate }
        }
        throw RunnerError.configuration("\(executable) was not found in PATH")
    }

    private final class ErrorBox: @unchecked Sendable {
        private let lock = NSLock()
        private var stored = Data()
        var data: Data {
            get { lock.withLock { stored } }
            set { lock.withLock { stored = newValue } }
        }
    }
}
