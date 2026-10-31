import Foundation
import Network

/// How long an import upload keeps retrying a failed request before it pauses (it continues on
/// its own when the app is next opened). Waits grow exponentially with random jitter, so many
/// devices that lost the same connection do not all come back at the same moment.
nonisolated struct ImportRetryPolicy: Sendable {
    /// Retries after the first attempt; the request fails for good after that many.
    var maxRetries: Int
    var baseDelay: Duration
    var maxDelay: Duration

    /// About five minutes of retries (offline time does not count: see `NetworkReachability`).
    static let standard = ImportRetryPolicy(maxRetries: 10, baseDelay: .seconds(1), maxDelay: .seconds(30))

    /// The wait before retry number `attempt` (1-based): base·2^(attempt-1), capped, then a random
    /// share between half and all of it.
    func delay(forAttempt attempt: Int) -> Duration {
        let exponent = Double(min(max(attempt - 1, 0), 16))
        let base = Double(baseDelay.components.seconds) + Double(baseDelay.components.attoseconds) / 1e18
        let cap = Double(maxDelay.components.seconds) + Double(maxDelay.components.attoseconds) / 1e18
        let full = min(base * pow(2, exponent), cap)
        return .milliseconds(Int((full * Double.random(in: 0.5...1.0) * 1000).rounded()))
    }
}

/// Whether the device has a usable network path, and a way to wait for one without polling.
nonisolated protocol NetworkReachability: AnyObject, Sendable {
    var isOnline: Bool { get }
    /// Returns once a path is available (at once if it already is), or when the task is cancelled.
    func waitUntilOnline() async
}

/// Never offline: tests, and callers that do not care.
nonisolated final class AlwaysOnline: NetworkReachability {
    var isOnline: Bool { true }
    func waitUntilOnline() async {}
}

/// `NWPathMonitor`-backed reachability.
nonisolated final class NetworkPathReachability: NetworkReachability, @unchecked Sendable {
    static let shared = NetworkPathReachability()

    private let monitor = NWPathMonitor()
    private let lock = NSLock()
    private var online = true
    private var waiters: [UUID: CheckedContinuation<Void, Never>] = [:]

    init() {
        monitor.pathUpdateHandler = { [weak self] path in
            self?.update(online: path.status == .satisfied)
        }
        monitor.start(queue: DispatchQueue(label: "storefront.reachability"))
    }

    deinit {
        monitor.cancel()
    }

    var isOnline: Bool { lock.withLock { online } }

    func waitUntilOnline() async {
        let id = UUID()
        await withTaskCancellationHandler {
            await withCheckedContinuation { (continuation: CheckedContinuation<Void, Never>) in
                let resumeNow = lock.withLock { () -> Bool in
                    if online || Task.isCancelled { return true }
                    waiters[id] = continuation
                    return false
                }
                if resumeNow { continuation.resume() }
            }
        } onCancel: {
            lock.withLock { waiters.removeValue(forKey: id) }?.resume()
        }
    }

    private func update(online: Bool) {
        let ready = lock.withLock { () -> [CheckedContinuation<Void, Never>] in
            self.online = online
            guard online else { return [] }
            defer { waiters.removeAll() }
            return Array(waiters.values)
        }
        ready.forEach { $0.resume() }
    }
}
