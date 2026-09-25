import Foundation

/// Runs a command line tool without a shell (no string interpolation into
/// /bin/sh), capturing its output.
public enum Shell {
    public struct Result: Sendable {
        public let status: Int32
        public let output: String
        public let error: String
    }

    @discardableResult
    public static func run(_ executable: String, _ arguments: [String], in directory: URL? = nil) throws -> Result {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: executable)
        process.arguments = arguments
        process.currentDirectoryURL = directory
        let out = Pipe(), err = Pipe()
        process.standardOutput = out
        process.standardError = err
        try process.run()
        // Read before waiting so large outputs cannot fill the pipe and block.
        let outData = out.fileHandleForReading.readDataToEndOfFile()
        let errData = err.fileHandleForReading.readDataToEndOfFile()
        process.waitUntilExit()

        return Result(
            status: process.terminationStatus,
            output: String(decoding: outData, as: UTF8.self),
            error: String(decoding: errData, as: UTF8.self)
        )
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
}
