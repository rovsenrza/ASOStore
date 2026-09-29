import SwiftUI

/// The install CTA. Rendered only from backend state — the catalog's
/// `installState`, or the live installation the coordinator is following;
/// tapping never changes the state locally (FULL_PLAN §11).
struct AppActionButton: View {
    enum Style {
        /// Capsule for lists: brand-blue text on glass.
        case standard
        /// White text on glass, over hero artwork.
        case overImage
        /// Full width on the app page; preparation shows its steps.
        case prominent
    }

    let app: StoreApp
    var style: Style = .standard
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?

    private var state: InstallState {
        installations?.state(for: app) ?? app.installState
    }

    var body: some View {
        Group {
            if case .preparing(let progress) = state {
                preparing(progress)
                    .transition(.blurReplace.combined(with: .scale(0.92)))
            } else {
                action
                    .transition(.blurReplace.combined(with: .scale(0.92)))
            }
        }
        .animation(Motion.state, value: Phase(state))
        .sensoryFeedback(trigger: Phase(state)) { old, new in
            switch (old, new) {
            case (.preparing, .ready): .success
            case (_, .failed): .error
            default: nil
            }
        }
    }

    // MARK: Action

    private var action: some View {
        Button {
            Task { await installations?.act(on: app) }
        } label: {
            label
        }
        .buttonStyle(PressableStyle(scale: style == .prominent ? 0.98 : 0.94))
        .disabled(!state.isActionable || installations == nil)
        .opacity(state.isActionable ? 1 : 0.55)
        .accessibilityLabel("\(title) \(app.name)")
        .accessibilityHint(hint)
    }

    @ViewBuilder
    private var label: some View {
        switch style {
        case .prominent:
            HStack(spacing: 8) {
                if let symbol {
                    Image(systemName: symbol)
                        .symbolEffect(.bounce, value: state == .readyToInstall)
                }
                Text(title)
            }
            .font(.headline)
            .foregroundStyle(isProminentFilled ? .white : AppPalette.accent)
            .frame(maxWidth: .infinity, minHeight: 52)
            .modifier(Face(tint: isProminentFilled ? fill : nil))
        case .standard, .overImage:
            Text(title)
                .font(.subheadline.weight(.bold))
                .lineLimit(1)
                .padding(.horizontal, 16)
                .frame(minWidth: 88, minHeight: 34)
                .foregroundStyle(foreground)
                .modifier(Face(tint: state == .readyToInstall ? AppPalette.success : nil))
        }
    }

    // MARK: Preparing

    @ViewBuilder
    private func preparing(_ progress: Double?) -> some View {
        switch style {
        case .prominent:
            InstallProgressBar(progress: progress)
                .tint(AppPalette.accent)
                .padding(.horizontal, 18)
                .padding(.vertical, 14)
                .glassCard(cornerRadius: 22)
        case .standard, .overImage:
            SmoothedProgress(progress: progress) { shown, stage in
                HStack(spacing: 7) {
                    ProgressRing(progress: shown, lineWidth: 2.5)
                        .frame(width: 15, height: 15)
                    AnimatedPercent(value: shown)
                        .font(.footnote.weight(.bold))
                }
                .foregroundStyle(style == .overImage ? .white : AppPalette.accent)
                .tint(style == .overImage ? .white : AppPalette.accent)
                .padding(.horizontal, 12)
                .frame(minWidth: 88, minHeight: 34)
                .modifier(Face(tint: nil))
                .accessibilityElement(children: .ignore)
                .accessibilityLabel("Подготовка \(app.name)")
                .accessibilityValue("\(stage.title), \(Int(shown * 100)) %")
            }
        }
    }

    // MARK: Appearance

    private var isProminentFilled: Bool {
        switch state {
        case .get, .readyToInstall, .delivered, .updateAvailable: true
        default: false
        }
    }

    private var fill: Color {
        state == .readyToInstall ? AppPalette.success : AppPalette.accent
    }

    private var foreground: Color {
        switch (style, state) {
        case (.overImage, _), (_, .readyToInstall): .white
        default: AppPalette.accent
        }
    }

    private var symbol: String? {
        switch state {
        case .get, .delivered: "arrow.down"
        case .readyToInstall: "arrow.down.circle.fill"
        case .updateAvailable: "arrow.triangle.2.circlepath"
        case .failed: "arrow.clockwise"
        default: nil
        }
    }

    private var title: String {
        switch state {
        case .get, .readyToInstall: "Установить"
        // Lists have room for one word; the app page says it in full.
        case .delivered: style == .prominent ? "Установить снова" : "Установить"
        case .updateAvailable: "Обновить"
        case .failed: "Повторить"
        case .preparing: "Подготовка"
        case .notEligible, .unavailable: "Недоступно"
        }
    }

    private var hint: String {
        switch state {
        case .notEligible(reason: .unauthenticated): "Войдите в аккаунт, чтобы установить приложение."
        case .notEligible: "Устройство пока не готово к установке."
        case .unavailable: "Приложение сейчас недоступно для установки."
        case .delivered: "Приложение уже загружалось. Если его нет на экране «Домой», установите снова."
        default: ""
        }
    }
}

/// The CTA's coarse state: what the animation and haptics key on (progress changes are not transitions).
private enum Phase: Equatable {
    case idle, preparing, ready, failed

    init(_ state: InstallState) {
        switch state {
        case .preparing: self = .preparing
        case .readyToInstall: self = .ready
        case .failed: self = .failed
        default: self = .idle
        }
    }
}

/// Glass face of the CTA: tinted when it carries colour, clear glass otherwise.
private struct Face: ViewModifier {
    let tint: Color?

    func body(content: Content) -> some View {
        if let tint {
            content.glassCapsule(tint: tint)
        } else {
            content.glassCapsule()
        }
    }
}
