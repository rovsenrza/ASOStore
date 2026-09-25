import SwiftUI

/// The install CTA. Rendered only from backend state — the catalog's
/// `installState`, or the live installation the coordinator is following;
/// tapping never changes the state locally (FULL_PLAN §11).
struct AppActionButton: View {
    let app: StoreApp
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?

    private var state: InstallState {
        installations?.state(for: app) ?? app.installState
    }

    var body: some View {
        switch state {
        case .preparing(let progress):
            ProgressView(value: progress ?? 0)
                .progressViewStyle(.circular)
                .frame(minWidth: 72)
                .accessibilityLabel("Подготовка \(app.name)")
                .accessibilityValue(progress.map { "\(Int($0 * 100)) %" } ?? "")
        default:
            Button(title) {
                Task { await installations?.act(on: app) }
            }
            .font(.subheadline.weight(.bold))
            .buttonStyle(.bordered)
            .buttonBorderShape(.capsule)
            .disabled(!state.isActionable || installations == nil)
            .accessibilityLabel("\(title) \(app.name)")
            .accessibilityHint(hint)
        }
    }

    private var title: String {
        switch state {
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
        switch state {
        case .notEligible(reason: .unauthenticated): "Войдите в аккаунт, чтобы установить приложение."
        case .notEligible: "Устройство пока не готово к установке."
        case .unavailable: "Приложение сейчас недоступно для установки."
        case .delivered: "Приложение загружено на устройство."
        default: ""
        }
    }
}
