import SwiftUI

/// Account, opened from the person button in the header of every tab.
struct AccountSheet: View {
    @Environment(SessionStore.self) private var session
    @Environment(\.apiClient) private var apiClient
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @State private var portalURL: URL?

    var body: some View {
        NavigationStack {
            List {
                switch session.state {
                case .restoring:
                    Section { ProgressView("Проверяем вход…") }
                case .signedOut, .expired:
                    SignInSection()
                case .unverified:
                    Section {
                        Label("Нет связи с сервером. Данные аккаунта обновятся, когда связь появится.", systemImage: "wifi.slash")
                        Button("Повторить") { Task { await session.restore() } }
                    }
                case .signedIn(let user):
                    Section {
                        NavigationLink {
                            AccountDetailView(user: user)
                        } label: {
                            accountRow(user)
                        }
                        .accessibilityIdentifier("account-details")
                    }
                    Section {
                        NavigationLink("Подписки") { SubscriptionView(subscription: user.subscription) }
                    }
                }

                Section {
                    NavigationLink("Настройки приложения магазина") { StoreSettingsView() }
                    NavigationLink("Данные и хранилище") { DataStorageView(portalURL: portalURL) }
                }

                Section {
                    HStack(spacing: 12) {
                        shortcut("Уведомления", systemImage: "bell.badge")
                        shortcut("Язык приложения", systemImage: "globe")
                    }
                    .listRowInsets(EdgeInsets())
                    .listRowBackground(Color.clear)
                }

                if let portalURL {
                    Section("Помощь") {
                        Link(destination: portalURL.appending(path: "support.html")) {
                            Label("Поддержка", systemImage: "questionmark.circle")
                        }
                        Link(destination: portalURL.appending(path: "install.html")) {
                            Label("Восстановление Ru AppStore", systemImage: "arrow.clockwise")
                        }
                        Link(destination: portalURL.appending(path: "privacy.html")) {
                            Label("Конфиденциальность", systemImage: "hand.raised")
                        }
                    }
                }

                Section {
                    VStack(spacing: 6) {
                        BrandMark(size: 36)
                        Text("Ru AppStore \(Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "")")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity)
                    .listRowBackground(Color.clear)
                }
            }
            .navigationTitle("Аккаунт")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { dismiss() } label: { Image(systemName: "xmark") }
                        .accessibilityLabel("Закрыть")
                }
            }
            .refreshable { await session.refreshUser() }
            .task { portalURL = await apiClient.environment.portalURL }
        }
    }

    private func accountRow(_ user: MeDTO) -> some View {
        HStack(spacing: 14) {
            Text(user.name.prefix(1).uppercased())
                .font(.title2.weight(.semibold))
                .foregroundStyle(.white)
                .frame(width: 56, height: 56)
                .background(AppPalette.accent.gradient, in: Circle())
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 3) {
                Text(user.name)
                    .font(.headline)
                Text("Информация об аккаунте и настройки")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 6)
    }

    /// Notifications and language are system settings of the app (iOS per-app language).
    private func shortcut(_ title: String, systemImage: String) -> some View {
        Button {
            if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
        } label: {
            VStack(spacing: 6) {
                Image(systemName: systemImage)
                Text(title).font(.subheadline.weight(.medium))
            }
            .frame(maxWidth: .infinity, minHeight: 64)
            .foregroundStyle(AppPalette.accent)
            .background(AppPalette.card, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

/// Членство and Устройство.
struct AccountDetailView: View {
    let user: MeDTO
    @Environment(SessionStore.self) private var session
    @Environment(\.apiClient) private var apiClient
    @Environment(\.dismiss) private var dismiss
    @State private var status: LoadState<StorefrontStatusDTO> = .loading
    @State private var copied = false

    var body: some View {
        List {
            Section("Профиль") {
                LabeledContent("Имя", value: user.name)
                LabeledContent("Эл. почта", value: user.email)
            }

            Section("Членство") {
                LabeledContent("План", value: user.subscription?.plan ?? "—")
                LabeledContent("Статус", value: MembershipText.status(user.subscription))
                LabeledContent("Действует до", value: MembershipText.until(user.subscription))
            }

            Section("Устройство") {
                switch status {
                case .loading:
                    ProgressView()
                case .loaded(let value):
                    if let device = value.device {
                        LabeledContent("Это устройство", value: "Оканчивается на \(device.udidHint.suffix(4))")
                        LabeledContent("Регистрация", value: MembershipText.registration(device.registration?.status))
                        Button {
                            UIPasteboard.general.string = device.id
                            copied = true
                        } label: {
                            Label(copied ? "Код скопирован" : "Копировать код для поддержки", systemImage: copied ? "checkmark" : "doc.on.doc")
                        }
                    } else {
                        Text("Устройство ещё не зарегистрировано. Откройте сайт в Safari на этом iPhone.")
                            .foregroundStyle(.secondary)
                    }
                default:
                    Button("Не удалось загрузить · повторить") { Task { await load() } }
                }
            }

            Section {
                Button("Выйти", role: .destructive) {
                    Task {
                        await session.signOut()
                        dismiss()
                    }
                }
            }
        }
        .navigationTitle("Аккаунт")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
    }

    private func load() async {
        do {
            status = .loaded(try await apiClient.get("/storefront/status", as: StorefrontStatusDTO.self).data)
        } catch {
            status = LoadState(error: error)
        }
    }
}

struct SubscriptionView: View {
    let subscription: SubscriptionDTO?

    var body: some View {
        List {
            Section {
                LabeledContent("План", value: subscription?.plan ?? "—")
                LabeledContent("Статус", value: MembershipText.status(subscription))
                LabeledContent("Действует до", value: MembershipText.until(subscription))
            } footer: {
                Text("Подписка активируется кодом на сайте. Продление и тарифы — на странице «Тарифы».")
            }
        }
        .navigationTitle("Подписки")
        .navigationBarTitleDisplayMode(.inline)
    }
}

struct StoreSettingsView: View {
    @Environment(\.openURL) private var openURL
    @AppStorage("autoOpenInstallLinks") private var autoOpen = true

    var body: some View {
        List {
            Section {
                Toggle("Сразу открывать установку, когда приложение готово", isOn: $autoOpen)
            } footer: {
                Text("Если выключено, готовое приложение ждёт в «Менеджере», пока вы не нажмёте «Установить».")
            }
            Section {
                Button("Открыть настройки iOS") {
                    if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
                }
            }
            #if DEBUG
            DiagnosticsSection()
            #endif
        }
        .navigationTitle("Настройки")
        .navigationBarTitleDisplayMode(.inline)
    }
}

struct DataStorageView: View {
    let portalURL: URL?
    @State private var cleared = false

    var body: some View {
        List {
            Section {
                Button(cleared ? "Кэш очищен" : "Очистить кэш каталога") {
                    ResponseCache.catalog.clear()
                    cleared = true
                }
                .disabled(cleared)
            } footer: {
                Text("Кэш позволяет смотреть каталог без сети. После очистки он заполнится снова.")
            }
            if let portalURL {
                Section {
                    Link("Скачать мои данные", destination: portalURL.appending(path: "account.html"))
                    Link("Удалить аккаунт", destination: portalURL.appending(path: "account.html"))
                } footer: {
                    Text("Экспорт и удаление данных выполняются в аккаунте на сайте.")
                }
            }
        }
        .navigationTitle("Данные и хранилище")
        .navigationBarTitleDisplayMode(.inline)
    }
}

enum MembershipText {
    static func status(_ subscription: SubscriptionDTO?) -> String {
        guard let subscription else { return "Не активирована" }
        if let endsAt = subscription.endsAt, endsAt < .now { return "Истекла" }
        return subscription.status == "ACTIVE" ? "Активна" : "Истекла"
    }

    static func until(_ subscription: SubscriptionDTO?) -> String {
        guard let subscription else { return "—" }
        return subscription.endsAt?.formatted(date: .long, time: .omitted) ?? "Без ограничения"
    }

    static func registration(_ status: String?) -> String {
        switch status {
        case "ELIGIBLE": "Зарегистрировано"
        case "APPLE_PENDING", "ENROLLED": "Регистрируется"
        case "QUOTA_BLOCKED", "NO_ELIGIBLE_TEAM": "Временно недоступна"
        case "APPLE_FAILED": "Ошибка регистрации"
        case "DISABLED": "Отключено"
        default: "Ожидает подключения"
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    AccountSheet()
        .environment(SessionStore(api: api))
        .environment(\.apiClient, api)
}
