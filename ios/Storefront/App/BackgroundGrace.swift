import UIKit

/// A short stay of execution after the app leaves the screen, so a preparation that is
/// about to finish can still be reported with a «готово к установке» notification.
/// iOS grants about half a minute; this asks for less and gives it back as soon as it can.
final class BackgroundGrace {
    private var identifier = UIBackgroundTaskIdentifier.invalid
    private var watch: Task<Void, Never>?

    /// - Parameters:
    ///   - isNeeded: checked every couple of seconds; the grace ends early once it is false.
    ///   - onEnd: called once, when the time is up or no longer needed (not after `cancel()`).
    func begin(for duration: Duration = .seconds(25), isNeeded: @escaping () -> Bool, onEnd: @escaping () -> Void) {
        cancel()
        identifier = UIApplication.shared.beginBackgroundTask(withName: "install-progress") { [weak self] in
            // iOS is out of patience: stop now.
            self?.finish(onEnd)
        }
        guard identifier != .invalid else {
            onEnd()
            return
        }
        watch = Task { [weak self] in
            let deadline = ContinuousClock.now + duration
            while ContinuousClock.now < deadline, isNeeded() {
                try? await Task.sleep(for: .seconds(2))
                if Task.isCancelled { return }
            }
            self?.finish(onEnd)
        }
    }

    /// The app is active again: nothing to end.
    func cancel() {
        watch?.cancel()
        watch = nil
        release()
    }

    private func finish(_ onEnd: () -> Void) {
        guard identifier != .invalid else { return }
        watch?.cancel()
        watch = nil
        onEnd()
        release()
    }

    private func release() {
        guard identifier != .invalid else { return }
        UIApplication.shared.endBackgroundTask(identifier)
        identifier = .invalid
    }
}
