import SwiftUI

/// The install CTA. Rendered only from the backend-provided `installState`;
/// tapping never changes the state locally (FULL_PLAN §11).
struct AppActionButton: View {
    let app: StoreApp
    /// Starts preparation/installation. Connected to InstallationCoordinator in Phase 6.
    var onAction: (InstallState) -> Void = { _ in }

    var body: some View {
        switch app.installState {
        case .preparing(let progress):
            ProgressView(value: progress ?? 0)
                .progressViewStyle(.circular)
                .frame(minWidth: 72)
                .accessibilityLabel("Подготовка \(app.name)")
                .accessibilityValue(progress.map { "\(Int($0 * 100)) %" } ?? "")
        default:
            Button(title) {
                onAction(app.installState)
            }
            .font(.subheadline.weight(.bold))
            .buttonStyle(.bordered)
            .buttonBorderShape(.capsule)
            .disabled(!app.installState.isActionable)
            .accessibilityLabel("\(title) \(app.name)")
            .accessibilityHint(hint)
        }
    }

    private var title: String {
        switch app.installState {
        case .get: "Получить"
        case .readyToInstall: "Установить"
        case .updateAvailable: "Обновить"
        case .failed: "Повторить"
        case .delivered: "Загружено"
        case .preparing: "Подготовка"
        case .notEligible, .unavailable: "Недоступно"
        }
    }

    private var hint: String {
        switch app.installState {
        case .notEligible(reason: .unauthenticated): "Войдите в аккаунт, чтобы установить приложение."
        case .notEligible: "Устройство пока не готово к установке."
        case .unavailable: "Приложение сейчас недоступно для установки."
        case .delivered: "Приложение загружено на устройство."
        default: ""
        }
    }
}
