import Foundation
#if os(Linux)
import Glibc
#else
import Darwin
#endif

/// Runs a command line tool without a shell (no string interpolation into
/// /bin/sh), capturing its output.
///
/// Nothing here touches the Swift concurrency pool or GCD: both pipes are drained and the
/// child is watched on dedicated threads, so many concurrent runs cannot starve each other
/// (a GCD stderr reader waited on from a cooperative thread deadlocked once every core was
/// busy). The calling thread blocks until the tool ends; async code calls the tool through
/// `Blocking.run` (see Signer.signDetached), never directly.
public enum Shell {
    public struct Result: Sendable {
        public let status: Int32
        public let data: Data
        public let error: String
        /// The tool was ended by a signal it did not get from us (e.g. the OOM killer).
        public let killedBySignal: Bool

        public var output: String { String(decoding: data, as: UTF8.self) }
    }

    /// Deadline for tools that do not say otherwise (openssl, small zips).
    public static let defaultTimeout: TimeInterval = 300

    /// 120 s plus 60 s per GB of input, at most 30 minutes (zsign, unzip, zip).
    public static func timeout(forBytes bytes: Int64) -> TimeInterval {
        min(1800, 120 + 60 * Double(max(0, bytes)) / 1_000_000_000)
    }

    public static func timeout(forFile file: URL) -> TimeInterval {
        let size = (try? FileManager.default.attributesOfItem(atPath: file.path)[.size] as? NSNumber)?.int64Value ?? 0
        return timeout(forBytes: size)
    }

    @discardableResult
    public static func run(_ executable: String, _ arguments: [String], in directory: URL? = nil, timeout: TimeInterval = defaultTimeout) throws -> Result {
        let cancellation = Cancellation.current
        try cancellation?.check()
        let process = Process()
        process.executableURL = URL(fileURLWithPath: try locate(executable))
        process.arguments = arguments
        process.currentDirectoryURL = directory
        let out = Pipe(), err = Pipe()
        process.standardOutput = out
        process.standardError = err
        process.standardInput = FileHandle.nullDevice
        try process.run()

        let outBox = DataBox(), errBox = DataBox()
        let drained = DispatchSemaphore(value: 0)
        for (pipe, box) in [(out, outBox), (err, errBox)] {
            let thread = Thread {
                box.data = pipe.fileHandleForReading.readDataToEndOfFile()
                drained.signal()
            }
            thread.stackSize = 256 * 1024
            thread.start()
        }

        let started = Date()
        var timedOut = false, cancelled = false
        var terminatedAt: Date?
        var pause: useconds_t = 2_000
        while process.isRunning {
            usleep(pause)
            pause = min(pause * 2, 100_000)
            if terminatedAt == nil {
                if Date().timeIntervalSince(started) > timeout { timedOut = true }
                if cancellation?.isCancelled == true { cancelled = true }
                if timedOut || cancelled {
                    // SIGTERM first, SIGKILL if the tool has not gone 10 s later.
                    process.terminate()
                    terminatedAt = Date()
                }
            } else if let at = terminatedAt, Date().timeIntervalSince(at) > 10 {
                kill(process.processIdentifier, SIGKILL)
                terminatedAt = .distantFuture
            }
        }
        // Normally both pipes hit EOF as the tool exits. A grandchild still holding a pipe
        // must not block the job: give up on its output after a short wait.
        let drainDeadline = DispatchTime.now() + (timedOut || cancelled ? 5 : 60)
        for _ in 0..<2 { _ = drained.wait(timeout: drainDeadline) }

        let name = URL(fileURLWithPath: executable).lastPathComponent
        if cancelled {
            throw RunnerError.job(code: "CANCELLED", message: "\(name) was stopped: the job was cancelled", retryable: true)
        }
        if timedOut {
            throw RunnerError.job(code: "TIMEOUT", message: "\(name) did not finish within \(Int(timeout)) s", retryable: true)
        }
        return Result(
            status: process.terminationStatus,
            data: outBox.data,
            error: String(decoding: errBox.data, as: UTF8.self),
            killedBySignal: process.terminationReason == .uncaughtSignal
        )
    }

    /// Like run, but a non-zero exit becomes a job error with the given code.
    @discardableResult
    public static func require(_ code: String, _ executable: String, _ arguments: [String], in directory: URL? = nil, timeout: TimeInterval = defaultTimeout) throws -> Result {
        let result = try run(executable, arguments, in: directory, timeout: timeout)
        guard result.status == 0 else {
            let message = (result.error.isEmpty ? result.output : result.error).trimmingCharacters(in: .whitespacesAndNewlines)
            throw RunnerError.job(code: code, message: Shell.truncated(message), retryable: result.killedBySignal)
        }
        return result
    }

    /// The end of a long tool message (where the error usually is), at most `limit` characters.
    public static func truncated(_ message: String, limit: Int = 1500) -> String {
        guard message.count > limit else { return message }
        return "…" + message.suffix(limit - 1)
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

    private final class DataBox: @unchecked Sendable {
        private let lock = NSLock()
        private var stored = Data()
        var data: Data {
            get { lock.withLock { stored } }
            set { lock.withLock { stored = newValue } }
        }
    }
}

/// A flag a job's tools check: set it and the running tool is stopped (SIGTERM, then SIGKILL)
/// and no new one starts. Blocking.run installs it as the worker thread's `current`.
public final class Cancellation: @unchecked Sendable {
    private let lock = NSLock()
    private var cancelled = false

    public init() {}

    public var isCancelled: Bool { lock.withLock { cancelled } }
    public func cancel() { lock.withLock { cancelled = true } }

    func check() throws {
        if isCancelled { throw RunnerError.job(code: "CANCELLED", message: "The job was cancelled", retryable: true) }
    }

    private static let key = "storefront-runner.cancellation"

    /// The cancellation of the job this thread works for, if any.
    public static var current: Cancellation? {
        Thread.current.threadDictionary[key] as? Cancellation
    }

    static func install(_ cancellation: Cancellation?) {
        Thread.current.threadDictionary[key] = cancellation
    }
}

/// Runs blocking work (child processes, hashing, unzipping) on its own thread, so it never
/// occupies one of the few threads Swift concurrency's cooperative pool has.
public enum Blocking {
    public static func run<T: Sendable>(cancellation: Cancellation? = nil, _ body: @escaping @Sendable () throws -> T) async throws -> T {
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<T, Error>) in
            let thread = Thread {
                Cancellation.install(cancellation)
                defer { Cancellation.install(nil) }
                continuation.resume(with: Result { try body() })
            }
            thread.stackSize = 8 * 1024 * 1024
            thread.start()
        }
    }
}
