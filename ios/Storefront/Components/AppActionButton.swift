import SwiftUI

/// The install CTA. Rendered only from backend state — the catalog's
/// `installState`, or the live installation the coordinator is following;
/// tapping never changes the state locally (FULL_PLAN §11).
///
/// When the server says this user or device cannot install yet, the button says what to
/// do about it instead of a dead «Недоступно»: «Войти», «Продлить», or an explanation on tap.
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
    @EnvironmentObject private var installations: InstallationCoordinator
    @EnvironmentObject private var router: AppRouter
    @Environment(\.apiClient) private var apiClient
    @Environment(\.openURL) private var openURL
    @State private var showsExplanation = false

    private var state: InstallState {
        installations.state(for: app)
    }

    /// Why the server will not let this user or device install yet, and what to offer instead.
    private var prompt: EligibilityPrompt? {
        if case .notEligible(let reason) = state { EligibilityPrompt(reason: reason) } else { nil }
    }

    /// A prepare or authorize request for this app is on its way: no second tap.
    private var isBusy: Bool {
        installations.isBusy(app.id)
    }

    private var isEnabled: Bool {
        !isBusy && (state.isActionable || prompt != nil)
    }

    var body: some View {
        Group {
            if case .preparing(let progress) = state {
                preparing(progress)
                    .swapTransition()
            } else {
                action
                    .swapTransition()
            }
        }
        .animation(Motion.state, value: Phase(state))
        .modifier(OutcomeFeedback(phase: Phase(state)))
    }

    // MARK: Action

    private var action: some View {
        VStack(spacing: 8) {
            Button(action: tap) {
                label
            }
            .buttonStyle(PressableStyle(scale: style == .prominent ? 0.98 : 0.94))
            .disabled(!isEnabled)
            .opacity(isEnabled || isBusy ? 1 : 0.55)
            .accessibilityLabel("\(title) \(app.name)")
            .accessibilityValue(isBusy ? "Загрузка" : "")
            .accessibilityHint(hint)
            .alert(prompt?.alertTitle ?? "", isPresented: $showsExplanation) {
                Button("Подробнее") { router.sheet = .account }
                Button("OK", role: .cancel) {}
            } message: {
                Text(prompt?.explanation ?? "")
            }

            // The app page has room to say why; lists explain on tap.
            if style == .prominent, let note {
                Text(note)
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                    .accessibilityHidden(true)
            }
        }
    }

    private func tap() {
        switch prompt {
        case .signIn?:
            router.sheet = .account
        case .renew?:
            if let url = apiClient.environment.renewURL { openURL(url) }
        case .waiting?, .blocked?:
            showsExplanation = true
        case nil:
            Task { await installations.act(on: app) }
        }
    }

    @ViewBuilder
    private var label: some View {
        switch style {
        case .prominent:
            HStack(spacing: 8) {
                if isBusy {
                    ProgressView()
                        .tint(isProminentFilled ? .white : AppPalette.accent)
                } else if let symbol {
                    Image(systemName: symbol)
                        .bounceSymbol(value: state == .readyToInstall)
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
                .minimumScaleFactor(0.8)
                .opacity(isBusy ? 0 : 1)
                .overlay {
                    if isBusy {
                        ProgressView()
                            .controlSize(.small)
                            .tint(foreground)
                    }
                }
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
        // Signing in and renewing are the way forward, so they look like it.
        case .notEligible: prompt == .signIn || prompt == .renew
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
        case .notEligible:
            switch prompt {
            case .signIn?: "person.crop.circle"
            case .waiting?: "clock"
            default: nil
            }
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
        case .notEligible: prompt?.title ?? "Недоступно"
        case .unavailable: "Недоступно"
        }
    }

    /// One line under the full-width button when the app cannot be installed right now.
    private var note: String? {
        switch state {
        case .notEligible: prompt?.explanation
        case .unavailable: "Приложение сейчас недоступно для установки."
        default: nil
        }
    }

    private var hint: String {
        switch state {
        case .notEligible: prompt?.explanation ?? ""
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

/// Success or error haptic as an installation finishes; silent on iOS 16.
private struct OutcomeFeedback: ViewModifier {
    let phase: Phase

    func body(content: Content) -> some View {
        if #available(iOS 17, *) {
            content.sensoryFeedback(trigger: phase) { old, new in
                switch (old, new) {
                case (.preparing, .ready): .success
                case (_, .failed): .error
                default: nil
                }
            }
        } else {
            content
        }
    }
}

private extension View {
    /// The button and its progress replace each other with a blur (a fade on iOS 16).
    @ViewBuilder
    func swapTransition() -> some View {
        if #available(iOS 17, *) {
            transition(.blurReplace.combined(with: .scale(0.92)))
        } else {
            transition(.opacity.combined(with: .scale(scale: 0.92)))
        }
    }

    @ViewBuilder
    func bounceSymbol(value: Bool) -> some View {
        if #available(iOS 17, *) {
            symbolEffect(.bounce, value: value)
        } else {
            self
        }
    }
}
