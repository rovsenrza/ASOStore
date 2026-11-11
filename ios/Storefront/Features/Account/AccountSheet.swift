import SwiftUI

/// Account, opened from the person button in the header of every tab.
struct AccountSheet: View {
    @EnvironmentObject private var session: SessionStore
    @Environment(\.apiClient) private var apiClient
    @Environment(\.dismiss) private var dismiss
    @AppStorage(SubscriptionExpiryNotice.dismissedAtKey) private var noticeDismissedAt = 0.0
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
                    if showsExpiryBanner(user) {
                        Section {
                            SubscriptionExpiryBanner()
                                .listRowInsets(EdgeInsets())
                                .listRowBackground(Color.clear)
                        }
                    }
                    Section {
                        NavigationLink {
                            AccountDetailView(user: user)
                        } label: {
                            accountRow(user)
                        }
                        .accessibilityIdentifier("account-details")
                    }
                    Section {
                        NavigationLink("Подписки") { SubscriptionView(subscription: user.subscription, portalURL: portalURL) }
                    }
                }

                Section {
                    NavigationLink("Настройки приложения магазина") { StoreSettingsView() }
                    NavigationLink("Данные и хранилище") { DataStorageView(portalURL: portalURL) }
                }

                NotificationPermissionRow()

                if let portalURL {
                    Section("Помощь") {
                        Link(destination: portalURL.appending(path: "support.html")) {
                            Label("Поддержка", systemImage: "questionmark.circle")
                        }
                        Link(destination: portalURL.appending(path: "install.html")) {
                            Label("Восстановление Ru App Store", systemImage: "arrow.clockwise")
                        }
                        Link(destination: portalURL.appending(path: "privacy.html")) {
                            Label("Конфиденциальность", systemImage: "hand.raised")
                        }
                    }
                }

                Section {
                    VStack(spacing: 6) {
                        BrandMark(size: 36)
                        Text("Ru App Store \(Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "")")
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
            .task { portalURL = try? apiClient.environment.portalURL }
        }
    }

    /// Same rule as the banner itself, so the list has no empty section when it is hidden.
    private func showsExpiryBanner(_ user: MeDTO) -> Bool {
        let closed = noticeDismissedAt > 0 ? Date(timeIntervalSinceReferenceDate: noticeDismissedAt) : nil
        return SubscriptionExpiryNotice(subscription: user.subscription) != nil && !SubscriptionExpiryNotice.isDismissed(at: closed)
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
}

/// Членство and Устройство.
struct AccountDetailView: View {
    let user: MeDTO
    @EnvironmentObject private var session: SessionStore
    @Environment(\.apiClient) private var apiClient
    @Environment(\.dismiss) private var dismiss
    @State private var status: LoadState<StorefrontStatusDTO> = .loading
    @State private var copied = false
    @State private var confirmsSignOut = false

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
                    confirmsSignOut = true
                }
                .accessibilityIdentifier("sign-out")
                .confirmationDialog("Выйти из аккаунта?", isPresented: $confirmsSignOut, titleVisibility: .visible) {
                    Button("Выйти", role: .destructive) {
                        Task {
                            await session.signOut()
                            dismiss()
                        }
                    }
                    Button("Отмена", role: .cancel) {}
                } message: {
                    Text("Установленные приложения останутся на iPhone. Чтобы устанавливать и обновлять их, нужно будет войти снова.")
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
    var portalURL: URL?

    var body: some View {
        List {
            Section {
                LabeledContent("План", value: subscription?.plan ?? "—")
                LabeledContent("Статус", value: MembershipText.status(subscription))
                LabeledContent("Действует до", value: MembershipText.until(subscription))
            } footer: {
                Text("Покупка разовая, без автопродления. Продлить доступ можно на сайте, на странице «Купить доступ».")
            }
            if let portalURL {
                Section {
                    Link(destination: portalURL.appending(path: "buy.html")) {
                        Label(subscription == nil ? "Купить доступ" : "Продлить", systemImage: "creditcard")
                    }
                }
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
    /// Backend `SubscriptionStatus`: ACTIVE or CANCELLED. An active one whose end date has
    /// passed has run out; statuses newer than this build are not called «Истекла».
    static func status(_ subscription: SubscriptionDTO?, now: Date = .now) -> String {
        guard let subscription else { return "Не активирована" }
        let hasEnded = subscription.endsAt.map { $0 < now } ?? false
        switch subscription.status {
        case "ACTIVE": return hasEnded ? "Истекла" : "Активна"
        case "CANCELLED": return "Отменена"
        default: return hasEnded ? "Истекла" : "Неактивна"
        }
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
    AccountSheet()
        .previewEnvironment()
}
