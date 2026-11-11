import SwiftUI

/// «Уведомления» in the account sheet: whether iOS lets the app say «готово к установке»,
/// and the way to change it — the system prompt the first time, iOS Settings afterwards.
struct NotificationPermissionRow: View {
    @EnvironmentObject private var installations: InstallationCoordinator
    @Environment(\.openURL) private var openURL
    @Environment(\.scenePhase) private var scenePhase
    @State private var permission: NotificationPermission?

    var body: some View {
        Section {
            Button {
                Task { await change() }
            } label: {
                LabeledContent {
                    Text(value)
                        .foregroundStyle(permission == .notDetermined ? AppPalette.accent : .secondary)
                } label: {
                    Label("Уведомления", systemImage: permission == .denied ? "bell.slash" : "bell.badge")
                        .foregroundStyle(.primary)
                }
            }
            .accessibilityHint(hint)
            .accessibilityIdentifier("notifications-permission")
        } footer: {
            Text(footer)
        }
        // Re-read on return from iOS Settings.
        .task(id: scenePhase) {
            guard scenePhase == .active else { return }
            permission = await installations.notifier.permission()
        }
    }

    private var value: String {
        switch permission {
        case .allowed: "Включены"
        case .denied: "Выключены"
        case .notDetermined: "Включить"
        case nil: ""
        }
    }

    private var hint: String {
        permission == .notDetermined ? "Разрешить уведомления" : "Открыть настройки уведомлений iOS"
    }

    private var footer: String {
        switch permission {
        case .denied: "Уведомления выключены в настройках iOS. Нажмите, чтобы открыть их и включить."
        default: "Сообщим, когда приложение подготовлено и ждёт установки, даже если Ru App Store свёрнут."
        }
    }

    private func change() async {
        if permission == .notDetermined {
            permission = await installations.notifier.requestPermission()
        } else if let url = URL(string: UIApplication.openNotificationSettingsURLString) {
            openURL(url)
        }
    }
}
