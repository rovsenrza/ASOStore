import Foundation
import Combine
import OSLog

/// One poll about to be scheduled: how many unchanged answers came before it, and how many
/// requests in a row have failed.
nonisolated struct PollAttempt: Equatable, Sendable {
    var attempt: Int
    var failures = 0
}

/// Drives prepare → poll → authorize → open itms-services (IMPLEMENTATION_PLAN §5.6, P6-IOS-01).
///
/// State always comes from the server; the coordinator only remembers which
/// installations are in flight so that progress resumes after the app is
/// killed and relaunched.
@MainActor
final class InstallationCoordinator: ObservableObject {
    /// Latest server state per app ID, for installations started or resumed here.
    @Published private(set) var installations: [String: InstallationDTO] = [:]
    @Published private(set) var lastError: APIError?
    /// Ready-to-install rows the user cleared from the bell, kept per installation id so a freshly
    /// prepared build notifies again. Observable so the bell badge and the list react to a clear.
    @Published private(set) var dismissedNotifications: Set<String>
    /// Apps with a prepare or authorize request in flight: their CTA is busy and ignores taps.
    @Published private(set) var busy: Set<String> = []

    /// Local «готово к установке» notifications; the account sheet shows their permission.
    let notifier: any InstallNotifying

    private let repository: PreparationRepository
    private let store: ActiveInstallationStore
    private let dismissedStore: DismissedNotificationsStore
    private let openURL: @MainActor (URL) async -> Bool
    private let pollDelay: @Sendable (PollAttempt) -> Duration
    private let now: @Sendable () -> Date
    /// Polling gives up after this long without the server reporting anything new.
    private let stallLimit: TimeInterval
    private var polls: [String: Task<Void, Never>] = [:]
    /// Per app ID: when the server last reported a change (or polling last started).
    private var lastChange: [String: Date] = [:]
    /// Apps the user asked to install in this session: open the install link as soon as it is ready.
    private var autoInstall: Set<String> = []
    /// Apps whose stale installation was replaced by a fresh one, so a refusal cannot loop.
    private var restarted: Set<String> = []
    /// The cleared ids in the order they were cleared, oldest first, so the cap drops the oldest.
    private var dismissedOrder: [String]
    /// Installation ids already announced with a local notification.
    private var announced: Set<String> = []
    /// The one-time notification permission prompt, while it is on screen.
    private var permissionPrompt: Task<Void, Never>?
    private var askedPermission = false
    private var isInBackground = false
    /// No polling at all: the app is in the background and its grace period is over.
    private var isPaused = false
    private var isResuming = false
    private let log = Logger(subsystem: "storefront", category: "installations")

    init(
        repository: PreparationRepository,
        store: ActiveInstallationStore = .shared,
        dismissedStore: DismissedNotificationsStore = .shared,
        notifier: any InstallNotifying = SilentInstallNotifier(),
        openURL: @escaping @MainActor (URL) async -> Bool,
        pollDelay: @escaping @Sendable (PollAttempt) -> Duration = InstallationCoordinator.defaultPollDelay,
        stallLimit: TimeInterval = 15 * 60,
        now: @escaping @Sendable () -> Date = { Date() }
    ) {
        self.repository = repository
        self.store = store
        self.dismissedStore = dismissedStore
        self.notifier = notifier
        self.openURL = openURL
        self.pollDelay = pollDelay
        self.stallLimit = stallLimit
        self.now = now
        dismissedOrder = dismissedStore.load()
        dismissedNotifications = Set(dismissedOrder)
    }

    /// Every 1–5 s while the server answers; 5, 10, then 15 s apart while requests fail.
    nonisolated static func defaultPollDelay(_ poll: PollAttempt) -> Duration {
        if poll.failures > 0 {
            return .seconds(min(5 * poll.failures, 15))
        }
        return .seconds(min(1 + poll.attempt, 5))
    }

    /// Ready-to-install notifications still showing in the bell (cleared ones excluded).
    var pendingNotifications: [InstallationDTO] {
        installations.values
            .filter { $0.status == .readyToInstall && !dismissedNotifications.contains($0.id) }
    }

    func isNotificationDismissed(_ id: String) -> Bool {
        dismissedNotifications.contains(id)
    }

    /// Swipe-to-clear a single ready notification.
    func dismissNotification(_ id: String) {
        clearNotifications([id])
    }

    /// Clear-all: hide every ready notification currently shown.
    func clearNotifications(_ ids: some Sequence<String>) {
        var changed = false
        for id in ids where dismissedNotifications.insert(id).inserted {
            dismissedOrder.append(id)
            notifier.withdraw(installationID: id)
            changed = true
        }
        if changed {
            dismissedStore.save(dismissedOrder)
        }
    }

    /// The CTA state for an app: a live installation known here wins over the
    /// catalog snapshot, which may be older.
    func state(for app: StoreApp) -> InstallState {
        installations[app.id]?.installState ?? app.installState
    }

    /// A prepare or authorize request for this app is in flight.
    func isBusy(_ appID: String) -> Bool {
        busy.contains(appID)
    }

    /// The CTA was tapped. A second tap while the first request is in flight does nothing.
    func act(on app: StoreApp) async {
        guard !busy.contains(app.id) else { return }
        switch state(for: app) {
        // Delivered: the app may have been deleted since; the server reuses the signed build.
        case .get, .delivered, .updateAvailable, .failed:
            autoInstall.insert(app.id)
            await prepare(appID: app.id)
        case .readyToInstall:
            if let installation = installations[app.id] {
                await install(installation)
            } else {
                autoInstall.insert(app.id)
                await prepare(appID: app.id)
            }
        case .preparing:
            // Polling may have given up on a stalled preparation: look again.
            if let installation = installations[app.id], polls[app.id] == nil {
                startPoll(installation, attempt: 0)
            }
        case .unavailable, .notEligible:
            break
        }
    }

    /// A ready row was tapped in the bell: open its install link now, whatever the
    /// «Сразу открывать установку» setting says.
    func installNow(_ installation: InstallationDTO) async {
        let appID = installation.app.id
        guard !busy.contains(appID) else { return }
        lastError = nil
        if installations[appID] == nil {
            installations[appID] = installation
            if installation.isActive {
                store.save(appID: appID, installationID: installation.id)
            }
        }
        await install(installations[appID] ?? installation)
    }

    /// Follow an installation started elsewhere (an import's install): poll it, and open the
    /// install link as soon as it is ready, as if the CTA had been tapped.
    func follow(_ installation: InstallationDTO) {
        lastError = nil
        autoInstall.insert(installation.app.id)
        update(installation)
    }

    /// Settings → «Сразу открывать установку» (on by default).
    static var autoOpenEnabled: Bool {
        UserDefaults.standard.object(forKey: "autoOpenInstallLinks") as? Bool ?? true
    }

    func dismissError() {
        lastError = nil
    }

    /// Russian copy for install errors (the String Catalog holds the keys).
    nonisolated static func message(for error: APIError) -> String {
        switch error.code {
        case .unauthenticated, .sessionExpired: "Войдите в аккаунт, чтобы установить приложение."
        case .devicePendingApple: "Устройство ещё проходит регистрацию в Apple. Попробуйте позже."
        case .deviceNotEligible: "Это устройство пока не может устанавливать приложения. Проверьте статус на сайте."
        case .quotaExhausted, .noEligibleTeam: "Регистрация временно недоступна. Мы сообщим, когда можно будет продолжить."
        case .incompatibleDevice: "Приложение не поддерживает это устройство или версию iOS."
        case .artifactNotInstallable: "Приложение сейчас недоступно для установки."
        case .installTokenExpired: "Ссылка на установку устарела. Нажмите «Установить» ещё раз."
        case .offline, .networkError: "Нет связи с сервером. Проверьте интернет."
        default: error.requestID.map { "Повторите попытку позже. Код запроса: \($0)" } ?? "Повторите попытку позже."
        }
    }

    /// Resumes polling for installations that were in flight when the app last ran, or that
    /// polling stopped following (background, a stalled preparation, a signed-out session).
    func resume() async {
        guard !isResuming else { return }
        isResuming = true
        defer { isResuming = false }

        for (appID, installationID) in store.load() where polls[appID] == nil && !busy.contains(appID) {
            do {
                let installation = try await repository.installation(id: installationID)
                // The user may have tapped the CTA while this was loading; that request wins.
                guard polls[appID] == nil, !busy.contains(appID) else { continue }
                update(installation)
            } catch let error as APIError where error.code == .notFound {
                forget(appID)
            } catch {
                log.error("resume failed: \(String(describing: error), privacy: .public)")
            }
        }
    }

    // MARK: Scene phase

    /// Whether a preparation is being polled right now.
    var hasPreparationInFlight: Bool {
        polls.keys.contains { installations[$0]?.status == .preparing }
    }

    /// The scene went to the background. Polling stops at once, unless a preparation is in
    /// flight: then the caller may keep the app running briefly and call `pausePolling()`
    /// when that time is up. Returns whether it should.
    @discardableResult
    func enterBackground() -> Bool {
        isInBackground = true
        guard hasPreparationInFlight else {
            pausePolling()
            return false
        }
        return true
    }

    /// Stops every poll until the app is active again.
    func pausePolling() {
        isPaused = true
        for poll in polls.values {
            poll.cancel()
        }
        polls.removeAll()
        lastChange.removeAll()
    }

    /// The scene is active again: open what became ready meanwhile, then look at the rest.
    func becomeActive() async {
        isInBackground = false
        isPaused = false
        if Self.autoOpenEnabled {
            for installation in installations.values where installation.status == .readyToInstall && autoInstall.contains(installation.app.id) {
                await install(installation)
            }
        }
        await resume()
    }

    // MARK: Requests

    private func prepare(appID: String) async {
        guard busy.insert(appID).inserted else { return }
        defer { busy.remove(appID) }
        lastError = nil
        do {
            let installation = try await repository.prepare(appID: appID)
            if installation.status == .preparing {
                askForNotificationsOnce()
            }
            update(installation)
        } catch let error as APIError {
            lastError = error
            autoInstall.remove(appID)
        } catch {
            lastError = APIError(code: .internal)
            autoInstall.remove(appID)
        }
    }

    private func install(_ installation: InstallationDTO) async {
        let appID = installation.app.id
        guard busy.insert(appID).inserted else { return }
        defer { busy.remove(appID) }
        autoInstall.remove(appID)
        do {
            let link = try await repository.authorize(id: installation.id)
            restarted.remove(appID)
            if await openURL(link.installUrl) == false {
                lastError = APIError(code: .artifactNotInstallable)
            }
            notifier.withdraw(installationID: installation.id)
            // The manifest fetch moves the server state on; pick it up.
            startPoll(installation, attempt: 0)
        } catch let error as APIError where Self.isStale(error) && !restarted.contains(appID) {
            // The build behind this installation expired or was replaced after the app last
            // asked (it keeps the old READY_TO_INSTALL answer): start a fresh one, once.
            restarted.insert(appID)
            stopPoll(appID)
            installations[appID] = nil
            store.remove(appID: appID)
            notifier.withdraw(installationID: installation.id)
            autoInstall.insert(appID)
            // Hand the busy mark over to the prepare request (it takes its own).
            busy.remove(appID)
            await prepare(appID: appID)
        } catch let error as APIError {
            lastError = error
        } catch {
            lastError = APIError(code: .internal)
        }
    }

    /// Refusals that mean "this installation is over", not "you may not install".
    private static func isStale(_ error: APIError) -> Bool {
        [.artifactNotInstallable, .notFound, .installTokenExpired].contains(error.code)
    }

    /// The first time a preparation starts that the user will have to wait for: ask whether
    /// the app may say when it is ready. Never at launch.
    private func askForNotificationsOnce() {
        guard !askedPermission else { return }
        askedPermission = true
        permissionPrompt = Task { [notifier] in
            await notifier.requestPermission()
        }
    }

    // MARK: Server state

    private func update(_ installation: InstallationDTO) {
        let appID = installation.app.id
        let previous = installations[appID]
        installations[appID] = installation

        if installation.isActive {
            store.save(appID: appID, installationID: installation.id)
        } else {
            store.remove(appID: appID)
        }
        if let previous, previous.status == .readyToInstall, installation.status != .readyToInstall {
            notifier.withdraw(installationID: previous.id)
        }

        switch installation.status {
        case .preparing:
            startPoll(installation, attempt: 0)
        case .readyToInstall:
            stopPoll(appID)
            guard autoInstall.contains(appID) else { break }
            if isInBackground {
                // The link cannot be opened from the background: say it is ready instead, and
                // open it when the user comes back (becomeActive).
                announceReady(installation)
            } else if Self.autoOpenEnabled {
                Task {
                    // One system alert at a time: the install prompt follows the permission prompt.
                    await permissionPrompt?.value
                    guard autoInstall.contains(appID), !isInBackground else { return }
                    await install(installation)
                }
            }
        case .authorized, .manifestFetched:
            startPoll(installation, attempt: 2)
        case .delivered, .failed, .expired, .unknown:
            stopPoll(appID)
        }
    }

    private func announceReady(_ installation: InstallationDTO) {
        guard announced.insert(installation.id).inserted else { return }
        Task { [notifier] in
            await notifier.notifyReady(installation)
        }
    }

    /// The server no longer knows this installation: drop it everywhere.
    private func forget(_ appID: String) {
        stopPoll(appID)
        installations[appID] = nil
        autoInstall.remove(appID)
        store.remove(appID: appID)
    }

    // MARK: Polling

    private func startPoll(_ installation: InstallationDTO, attempt: Int) {
        let appID = installation.app.id
        lastChange[appID] = now()
        schedulePoll(installation.id, appID: appID, poll: PollAttempt(attempt: attempt))
    }

    private func stopPoll(_ appID: String) {
        polls[appID]?.cancel()
        polls[appID] = nil
        lastChange[appID] = nil
    }

    private func schedulePoll(_ id: String, appID: String, poll: PollAttempt) {
        polls[appID]?.cancel()
        polls[appID] = nil
        guard !isPaused else { return }
        polls[appID] = Task { [weak self, repository, pollDelay] in
            try? await Task.sleep(for: pollDelay(poll))
            guard !Task.isCancelled else { return }
            let result: Result<InstallationDTO, any Error>
            do {
                result = .success(try await repository.installation(id: id))
            } catch {
                result = .failure(error)
            }
            guard let self, !Task.isCancelled else { return }
            self.polls[appID] = nil
            self.finishPoll(result, id: id, appID: appID, poll: poll)
        }
    }

    private func finishPoll(_ result: Result<InstallationDTO, any Error>, id: String, appID: String, poll: PollAttempt) {
        switch result {
        case .success(let installation):
            let previous = installations[appID]
            guard installation.status == previous?.status, installation.status.isPolled else {
                update(installation)
                return
            }
            // Same step as before: note any progress and keep looking.
            if installation.preparation.progress != previous?.preparation.progress {
                lastChange[appID] = now()
            }
            installations[appID] = installation
            guard !isStalled(appID) else { return giveUp(appID) }
            schedulePoll(id, appID: appID, poll: PollAttempt(attempt: poll.attempt + 1))

        case .failure(let error):
            switch (error as? APIError)?.code {
            case .notFound?:
                forget(appID)
            case .unauthenticated?, .sessionExpired?:
                // Signed out: stop asking. The installation stays stored, and `resume()`
                // picks it up again after the next sign-in or launch.
                stopPoll(appID)
            default:
                guard !isStalled(appID) else { return giveUp(appID) }
                schedulePoll(id, appID: appID, poll: PollAttempt(attempt: poll.attempt, failures: poll.failures + 1))
            }
        }
    }

    private func isStalled(_ appID: String) -> Bool {
        guard let since = lastChange[appID] else { return false }
        return now().timeIntervalSince(since) >= stallLimit
    }

    /// Nothing new for too long: stop polling but keep the row. `resume()` (next time the
    /// app becomes active, or «Менеджер» is refreshed) and `act(on:)` look again.
    private func giveUp(_ appID: String) {
        log.notice("polling stalled for app \(appID, privacy: .public); stopped")
        lastChange[appID] = nil
    }
}

/// Notification rows the user cleared from the bell, in UserDefaults so a clear sticks across
/// launches. Ordered oldest first, so the cap drops the oldest ids.
nonisolated struct DismissedNotificationsStore: Sendable {
    static let shared = DismissedNotificationsStore()

    private let key = "dismissedNotificationIDs"
    let limit: Int
    /// nil is the standard defaults; tests use a suite of their own.
    let suiteName: String?

    init(suiteName: String? = nil, limit: Int = 300) {
        self.suiteName = suiteName
        self.limit = limit
    }

    private var defaults: UserDefaults {
        suiteName.flatMap(UserDefaults.init(suiteName:)) ?? .standard
    }

    func load() -> [String] {
        var seen = Set<String>()
        return (defaults.stringArray(forKey: key) ?? []).filter { seen.insert($0).inserted }
    }

    /// `ids` in the order they were cleared, newest last.
    func save(_ ids: [String]) {
        // Bound the stored list so it cannot grow without limit over the life of the install.
        defaults.set(Array(ids.suffix(limit)), forKey: key)
    }
}

/// Installations in flight, kept in Application Support so they survive relaunch.
nonisolated final class ActiveInstallationStore: Sendable {
    static let shared = ActiveInstallationStore(url: URL.applicationSupportDirectory.appending(path: "active-installations.json"))

    private let url: URL
    private let lock = NSLock()

    init(url: URL) {
        self.url = url
    }

    /// App ID → installation ID.
    func load() -> [String: String] {
        lock.withLock { read() }
    }

    func save(appID: String, installationID: String) {
        lock.withLock {
            var all = read()
            all[appID] = installationID
            write(all)
        }
    }

    func remove(appID: String) {
        lock.withLock {
            var all = read()
            guard all.removeValue(forKey: appID) != nil else { return }
            write(all)
        }
    }

    private func read() -> [String: String] {
        (try? JSONDecoder().decode([String: String].self, from: Data(contentsOf: url))) ?? [:]
    }

    private func write(_ value: [String: String]) {
        try? FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        try? JSONEncoder().encode(value).write(to: url, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }
}
