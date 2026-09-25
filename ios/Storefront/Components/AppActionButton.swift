import SwiftUI

/// The install CTA. Rendered only from backend state — the catalog's
/// `installState`, or the live installation the coordinator is following;
/// tapping never changes the state locally (FULL_PLAN §11).
struct AppActionButton: View {
    enum Style {
        /// Grey capsule with brand-blue text, for lists.
        case standard
        /// Translucent capsule with white text, over hero artwork.
        case overImage
    }

    let app: StoreApp
    var style: Style = .standard
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?

    private var state: InstallState {
        installations?.state(for: app) ?? app.installState
    }

    var body: some View {
        switch state {
        case .preparing(let progress):
            ProgressView(value: progress ?? 0)
                .progressViewStyle(.circular)
                .tint(style == .overImage ? .white : AppPalette.accent)
                .frame(minWidth: 96, minHeight: 36)
                .accessibilityLabel("Подготовка \(app.name)")
                .accessibilityValue(progress.map { "\(Int($0 * 100)) %" } ?? "")
        default:
            Button {
                Task { await installations?.act(on: app) }
            } label: {
                Text(title)
                    .font(.subheadline.weight(.bold))
                    .lineLimit(1)
                    .padding(.horizontal, 18)
                    .frame(minWidth: 96, minHeight: 36)
                    .foregroundStyle(foreground)
                    .background(background, in: Capsule())
            }
            .buttonStyle(.plain)
            .disabled(!state.isActionable || installations == nil)
            .opacity(state.isActionable ? 1 : 0.6)
            .accessibilityLabel("\(title) \(app.name)")
            .accessibilityHint(hint)
        }
    }

    private var foreground: Color {
        switch (style, state) {
        case (.overImage, _): .white
        case (_, .readyToInstall): .white
        default: AppPalette.accent
        }
    }

    private var background: AnyShapeStyle {
        switch (style, state) {
        case (.overImage, _): AnyShapeStyle(.white.opacity(0.28))
        // Ready means the build for this iPhone exists: the one step left stands out.
        case (_, .readyToInstall): AnyShapeStyle(AppPalette.success)
        default: AnyShapeStyle(AppPalette.ctaFill)
        }
    }

    private var title: String {
        switch state {
        case .get, .readyToInstall: "Установить"
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
