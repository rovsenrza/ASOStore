import Foundation
import UserNotifications

/// Whether iOS lets the app show notifications.
nonisolated enum NotificationPermission: Equatable, Sendable {
    /// Never asked.
    case notDetermined
    case denied
    case allowed
}

/// Local «готово к установке» notifications. A protocol so tests and previews can replace
/// the system notification centre. No push: nothing here needs APNs or an entitlement.
protocol InstallNotifying: AnyObject {
    func permission() async -> NotificationPermission
    /// Shows the system prompt if the user was never asked; returns the resulting permission.
    @discardableResult
    func requestPermission() async -> NotificationPermission
    /// «<App> готово к установке», if the user allowed notifications.
    func notifyReady(_ installation: InstallationDTO) async
    /// Removes a ready notification that is no longer true (installed, expired, cleared).
    func withdraw(installationID: String)
}

/// Previews and tests: no notifications.
final class SilentInstallNotifier: InstallNotifying {
    func permission() async -> NotificationPermission { .denied }
    func requestPermission() async -> NotificationPermission { .denied }
    func notifyReady(_ installation: InstallationDTO) async {}
    func withdraw(installationID: String) {}
}

/// The system notification centre. Tapping a ready notification calls `onOpen`.
final class SystemInstallNotifier: NSObject, InstallNotifying {
    nonisolated private static let kindKey = "kind"
    nonisolated private static let readyKind = "install-ready"

    private let center: UNUserNotificationCenter
    /// Set by the app: opens «Менеджер».
    var onOpen: (@MainActor () -> Void)?

    init(center: UNUserNotificationCenter = .current()) {
        self.center = center
        super.init()
        // Needed before launch finishes, so a tap that starts the app is delivered.
        center.delegate = self
    }

    func permission() async -> NotificationPermission {
        Self.permission(for: await center.notificationSettings().authorizationStatus)
    }

    func requestPermission() async -> NotificationPermission {
        let current = await permission()
        guard current == .notDetermined else { return current }
        let granted = (try? await center.requestAuthorization(options: [.alert, .sound])) ?? false
        return granted ? .allowed : .denied
    }

    func notifyReady(_ installation: InstallationDTO) async {
        guard await permission() == .allowed else { return }
        let content = UNMutableNotificationContent()
        content.title = "\(installation.app.name) готово к установке"
        content.body = "Откройте «Менеджер» и нажмите «Установить»."
        content.sound = .default
        content.userInfo = [Self.kindKey: Self.readyKind]
        // One notification per installation: a repeat replaces it instead of stacking.
        let request = UNNotificationRequest(identifier: Self.identifier(installation.id), content: content, trigger: nil)
        try? await center.add(request)
    }

    func withdraw(installationID: String) {
        center.removeDeliveredNotifications(withIdentifiers: [Self.identifier(installationID)])
    }

    private static func identifier(_ installationID: String) -> String {
        "install-ready-\(installationID)"
    }

    nonisolated static func permission(for status: UNAuthorizationStatus) -> NotificationPermission {
        switch status {
        case .notDetermined: .notDetermined
        case .denied: .denied
        case .authorized, .provisional, .ephemeral: .allowed
        @unknown default: .denied
        }
    }
}

extension SystemInstallNotifier: UNUserNotificationCenterDelegate {
    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let isReady = response.notification.request.content.userInfo[Self.kindKey] as? String == Self.readyKind
        if isReady, response.actionIdentifier == UNNotificationDefaultActionIdentifier {
            Task { @MainActor in self.onOpen?() }
        }
        completionHandler()
    }
}
