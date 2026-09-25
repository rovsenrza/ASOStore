import SwiftUI

struct AccountView: View {
    @Environment(SessionStore.self) private var session
    @Environment(\.apiClient) private var apiClient
    @State private var portalURL: URL?

    var body: some View {
        NavigationStack {
            List {
                switch session.state {
                case .restoring:
                    Section {
                        ProgressView("Проверяем вход…")
                    }
                case .signedOut, .expired:
                    SignInSection()
                case .unverified:
                    Section {
                        Label("Нет связи с сервером. Данные аккаунта обновятся, когда связь появится.", systemImage: "wifi.slash")
                        Button("Повторить") {
                            Task { await session.restore() }
                        }
                        signOutButton
                    }
                case .signedIn(let user):
                    Section {
                        accountHeader(user)
                    }
                    StorefrontStatusSection()
                    Section {
                        signOutButton
                    }
                }

                #if DEBUG
                DiagnosticsSection()
                #endif

                if let portalURL {
                    Section("Помощь") {
                        Link(destination: portalURL.appending(path: "support.html")) {
                            Label("Поддержка", systemImage: "questionmark.circle")
                        }
                        Link(destination: portalURL.appending(path: "install.html")) {
                            Label("Восстановление Storefront", systemImage: "arrow.clockwise")
                        }
                        Link(destination: portalURL.appending(path: "privacy.html")) {
                            Label("Конфиденциальность", systemImage: "hand.raised")
                        }
                    }
                }

                Section {
                    Text("Версия \(Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "—")")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                        .frame(maxWidth: .infinity)
                }
            }
            .navigationTitle("Аккаунт")
            .refreshable {
                await session.refreshUser()
            }
            .task {
                portalURL = await apiClient.environment.portalURL
            }
        }
    }

    private var signOutButton: some View {
        Button("Выйти", role: .destructive) {
            Task { await session.signOut() }
        }
    }

    private func accountHeader(_ user: MeDTO) -> some View {
        HStack(spacing: 16) {
            ZStack {
                Circle()
                    .fill(AppPalette.accent.gradient)
                Text(user.name.prefix(1).uppercased())
                    .font(.title.bold())
                    .foregroundStyle(.white)
            }
            .frame(width: 64, height: 64)
            .accessibilityHidden(true)

            VStack(alignment: .leading, spacing: 4) {
                Text(user.name)
                    .font(.headline)
                Text(user.email)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                Text(subscriptionText(user.subscription))
                    .font(.caption.weight(.medium))
                    .foregroundStyle(user.subscription == nil ? AnyShapeStyle(.secondary) : AnyShapeStyle(AppPalette.success))
            }
        }
        .padding(.vertical, 8)
    }

    private func subscriptionText(_ subscription: SubscriptionDTO?) -> String {
        guard let subscription else { return "Подписка не активирована" }
        guard let endsAt = subscription.endsAt else { return "Подписка без ограничения срока" }
        return "Подписка до \(endsAt.formatted(date: .long, time: .omitted))"
    }
}

/// The next step of the device setup, from /storefront/status.
private struct StorefrontStatusSection: View {
    @Environment(\.apiClient) private var apiClient
    @State private var status: LoadState<StorefrontStatusDTO> = .loading

    var body: some View {
        Section("Статус") {
            switch status {
            case .loading:
                ProgressView()
            case .loaded(let value):
                Label(title(for: value.stage), systemImage: symbol(for: value.stage))
                if let device = value.device {
                    LabeledContent("Устройство", value: device.product ?? device.family)
                    LabeledContent("Идентификатор", value: device.udidHint)
                    LabeledContent("Регистрация", value: registrationTitle(device.registration?.status))
                }
            default:
                Button("Не удалось загрузить статус · повторить") {
                    Task { await load() }
                }
            }
        }
        .task { await load() }
    }

    private func load() async {
        do {
            status = .loaded(try await apiClient.get("/storefront/status", as: StorefrontStatusDTO.self).data)
        } catch {
            status = LoadState(error: error)
        }
    }

    private func title(for stage: String) -> String {
        switch stage {
        case "activation_required": "Нужен код активации — введите его на сайте"
        case "device_required": "Зарегистрируйте iPhone на сайте"
        case "device_pending": "Устройство проходит проверку"
        case "storefront_ready", "storefront_installed": "Устройство готово"
        case "blocked": "Регистрация временно недоступна"
        default: "Статус обновится позже"
        }
    }

    private func registrationTitle(_ status: String?) -> String {
        switch status {
        case "ELIGIBLE": "Зарегистрировано"
        case "APPLE_PENDING", "ENROLLED": "Регистрируется"
        case "QUOTA_BLOCKED", "NO_ELIGIBLE_TEAM": "Временно недоступна"
        case "APPLE_FAILED": "Ошибка регистрации"
        case "DISABLED": "Отключено"
        default: "Ожидает подключения Apple"
        }
    }

    private func symbol(for stage: String) -> String {
        switch stage {
        case "storefront_ready", "storefront_installed": "checkmark.circle.fill"
        case "device_pending": "clock"
        case "blocked": "exclamationmark.triangle"
        default: "arrow.right.circle"
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    AccountView()
        .environment(SessionStore(api: api))
        .environment(AppRouter())
        .environment(\.apiClient, api)
}
