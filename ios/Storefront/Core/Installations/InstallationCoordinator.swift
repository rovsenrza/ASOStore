import Foundation
import Observation
import OSLog

/// Drives prepare → poll → authorize → open itms-services (IMPLEMENTATION_PLAN §5.6, P6-IOS-01).
///
/// State always comes from the server; the coordinator only remembers which
/// installations are in flight so that progress resumes after the app is
/// killed and relaunched.
@MainActor @Observable
final class InstallationCoordinator {
    /// Latest server state per app ID, for installations started or resumed here.
    private(set) var installations: [String: InstallationDTO] = [:]
    private(set) var lastError: APIError?
    /// Ready-to-install rows the user cleared from the bell, kept per installation id so a freshly
    /// prepared build notifies again. Observable so the bell badge and the list react to a clear.
    private(set) var dismissedNotifications: Set<String>

    @ObservationIgnored private let repository: PreparationRepository
    @ObservationIgnored private let store: ActiveInstallationStore
    @ObservationIgnored private let dismissedStore: DismissedNotificationsStore
    @ObservationIgnored private let openURL: @MainActor (URL) async -> Bool
    @ObservationIgnored private let pollDelay: @Sendable (Int) -> Duration
    @ObservationIgnored private var polls: [String: Task<Void, Never>] = [:]
    /// Apps the user asked to install in this session: open the install link as soon as it is ready.
    @ObservationIgnored private var autoInstall: Set<String> = []
    @ObservationIgnored private let log = Logger(subsystem: "storefront", category: "installations")

    init(
        repository: PreparationRepository,
        store: ActiveInstallationStore = .shared,
        dismissedStore: DismissedNotificationsStore = .shared,
        openURL: @escaping @MainActor (URL) async -> Bool,
        pollDelay: @escaping @Sendable (Int) -> Duration = { attempt in .seconds(min(1 + attempt, 5)) }
    ) {
        self.repository = repository
        self.store = store
        self.dismissedStore = dismissedStore
        self.openURL = openURL
        self.pollDelay = pollDelay
        dismissedNotifications = dismissedStore.load()
    }

    /// Ready-to-install notifications still showing in the bell (cleared ones excluded).
    var pendingNotifications: [InstallationDTO] {
        installations.values
            .filter { $0.status == "READY_TO_INSTALL" && !dismissedNotifications.contains($0.id) }
    }

    func isNotificationDismissed(_ id: String) -> Bool {
        dismissedNotifications.contains(id)
    }

    /// Swipe-to-clear a single ready notification.
    func dismissNotification(_ id: String) {
        guard dismissedNotifications.insert(id).inserted else { return }
        dismissedStore.save(dismissedNotifications)
    }

    /// Clear-all: hide every ready notification currently shown.
    func clearNotifications(_ ids: some Sequence<String>) {
        let before = dismissedNotifications.count
        dismissedNotifications.formUnion(ids)
        if dismissedNotifications.count != before {
            dismissedStore.save(dismissedNotifications)
        }
    }

    /// The CTA state for an app: a live installation known here wins over the
    /// catalog snapshot, which may be older.
    func state(for app: StoreApp) -> InstallState {
        installations[app.id]?.installState ?? app.installState
    }

    /// The CTA was tapped.
    func act(on app: StoreApp) async {
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
        case .unavailable, .notEligible, .preparing:
            break
        }
    }

    /// Settings → «Сразу открывать установку» (on by default).
    static var autoOpenEnabled: Bool {
        UserDefaults.standard.object(forKey: "autoOpenInstallLinks") as? Bool ?? true
    }

    func dismissError() {
        lastError = nil
    }

    /// Russian copy for install errors (the String Catalog holds the keys).
    static func message(for error: APIError) -> String {
        switch error.code {
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

    /// Resumes polling for installations that were in flight when the app last ran.
    func resume() async {
        for (appID, installationID) in store.load() where polls[appID] == nil {
            do {
                let installation = try await repository.installation(id: installationID)
                update(installation)
            } catch let error as APIError where error.code == .notFound {
                store.remove(appID: appID)
            } catch {
                log.error("resume failed: \(String(describing: error), privacy: .public)")
            }
        }
    }

    private func prepare(appID: String) async {
        lastError = nil
        do {
            update(try await repository.prepare(appID: appID))
        } catch let error as APIError {
            lastError = error
            autoInstall.remove(appID)
        } catch {
            lastError = APIError(code: .internal)
        }
    }

    private func install(_ installation: InstallationDTO) async {
        autoInstall.remove(installation.app.id)
        do {
            let link = try await repository.authorize(id: installation.id)
            if await openURL(link.installUrl) == false {
                lastError = APIError(code: .artifactNotInstallable)
            }
            // The manifest fetch moves the server state on; pick it up.
            schedulePoll(installation.id, appID: installation.app.id, attempt: 0)
        } catch let error as APIError {
            lastError = error
        } catch {
            lastError = APIError(code: .internal)
        }
    }

    private func update(_ installation: InstallationDTO) {
        let appID = installation.app.id
        installations[appID] = installation

        if installation.isActive {
            store.save(appID: appID, installationID: installation.id)
        } else {
            store.remove(appID: appID)
        }

        switch installation.status {
        case "PREPARING":
            schedulePoll(installation.id, appID: appID, attempt: 0)
        case "READY_TO_INSTALL" where autoInstall.contains(appID) && Self.autoOpenEnabled:
            Task { await install(installation) }
        case "AUTHORIZED", "MANIFEST_FETCHED":
            schedulePoll(installation.id, appID: appID, attempt: 2)
        default:
            polls[appID]?.cancel()
            polls[appID] = nil
        }
    }

    private func schedulePoll(_ id: String, appID: String, attempt: Int) {
        polls[appID]?.cancel()
        polls[appID] = Task { [weak self, repository, pollDelay] in
            try? await Task.sleep(for: pollDelay(attempt))
            guard !Task.isCancelled else { return }
            do {
                let installation = try await repository.installation(id: id)
                guard let self, !Task.isCancelled else { return }
                self.polls[appID] = nil
                if installation.status == self.installations[appID]?.status, installation.isActive, installation.status != "READY_TO_INSTALL" {
                    self.installations[appID] = installation
                    self.schedulePoll(id, appID: appID, attempt: attempt + 1)
                } else {
                    self.update(installation)
                }
            } catch {
                guard let self, !Task.isCancelled else { return }
                self.polls[appID] = nil
                self.schedulePoll(id, appID: appID, attempt: attempt + 1)
            }
        }
    }
}

/// Notification rows the user cleared from the bell, in UserDefaults so a clear sticks across launches.
nonisolated struct DismissedNotificationsStore: Sendable {
    static let shared = DismissedNotificationsStore()

    private let key = "dismissedNotificationIDs"
    private let limit = 300

    func load() -> Set<String> {
        Set(UserDefaults.standard.stringArray(forKey: key) ?? [])
    }

    func save(_ ids: Set<String>) {
        // Bound the stored set so it cannot grow without limit over the life of the install.
        UserDefaults.standard.set(Array(ids.suffix(limit)), forKey: key)
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
