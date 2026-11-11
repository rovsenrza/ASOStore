import SwiftUI

/// The bell: apps whose build for this iPhone is ready. These are the only
/// notifications the app has; nothing is invented to fill the list. Tapping a row
/// starts the install; each row can be swiped away, and «Очистить» clears them all.
struct NotificationsSheet: View {
    @EnvironmentObject private var installations: InstallationCoordinator
    @Environment(\.apiClient) private var api
    @Environment(\.dismiss) private var dismiss
    @State private var library: [InstallationDTO] = []

    var body: some View {
        NavigationStack {
            Group {
                if ready.isEmpty {
                    UnavailableView("Уведомлений нет", systemImage: "bell.slash", description: "Здесь появятся приложения, которые подготовлены для вашего iPhone и ждут установки.")
                } else {
                    List {
                        ForEach(ready) { item in
                            Button {
                                install(item)
                            } label: {
                                row(item)
                            }
                            .accessibilityHint("Начать установку")
                            .accessibilityIdentifier("notification-\(item.app.id)")
                            .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                                Button(role: .destructive) {
                                    withAnimation { installations.dismissNotification(item.id) }
                                } label: {
                                    Label("Очистить", systemImage: "trash")
                                }
                            }
                        }
                    }
                }
            }
            .navigationTitle("Уведомления")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if !ready.isEmpty {
                    ToolbarItem(placement: .topBarLeading) {
                        Button("Очистить") {
                            withAnimation { installations.clearNotifications(ready.map(\.id)) }
                        }
                    }
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Button { dismiss() } label: { Image(systemName: "xmark") }
                        .accessibilityLabel("Закрыть")
                }
            }
            .task { library = (try? await PreparationRepository(api: api).library()) ?? [] }
        }
    }

    private func row(_ item: InstallationDTO) -> some View {
        HStack(spacing: 12) {
            Label {
                VStack(alignment: .leading, spacing: 2) {
                    Text(item.app.name)
                        .font(.headline)
                        .foregroundStyle(.primary)
                    Text("Готово к установке")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            } icon: {
                Image(systemName: "arrow.down.app.fill").foregroundStyle(AppPalette.success)
            }
            Spacer(minLength: 8)
            Text("Установить")
                .font(.subheadline.weight(.bold))
                .foregroundStyle(AppPalette.accent)
        }
        .contentShape(Rectangle())
    }

    /// The sheet closes first: the system install prompt, or an error, shows over the app itself.
    private func install(_ item: InstallationDTO) {
        dismiss()
        Task { await installations.installNow(item) }
    }

    /// Ready-to-install installations the user has not cleared: the server's library, plus
    /// any the coordinator is following that the library request has not returned (yet).
    private var ready: [InstallationDTO] {
        let listed = library.map { installations.installations[$0.app.id] ?? $0 }
        let known = Set(listed.map(\.id))
        let followed = installations.pendingNotifications
            .filter { !known.contains($0.id) }
            .sorted { $0.app.name < $1.app.name }
        // The library can list one app twice (an older and a newer installation), and both map
        // to the installation being followed: show each once.
        var seen = Set<String>()
        return (listed + followed)
            .filter { $0.status == .readyToInstall && !installations.isNotificationDismissed($0.id) && seen.insert($0.id).inserted }
    }
}
