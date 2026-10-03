import SwiftUI

/// The bell: apps whose build for this iPhone is ready. These are the only
/// notifications the app has; nothing is invented to fill the list. Each row
/// can be swiped away, and «Очистить» clears them all.
struct NotificationsSheet: View {
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?
    @Environment(\.apiClient) private var api
    @Environment(\.dismiss) private var dismiss
    @State private var library: [InstallationDTO] = []

    var body: some View {
        NavigationStack {
            Group {
                if ready.isEmpty {
                    ContentUnavailableView("Уведомлений нет", systemImage: "bell.slash", description: Text("Здесь появятся приложения, которые подготовлены для вашего iPhone и ждут установки."))
                } else {
                    List {
                        ForEach(ready) { item in
                            Label {
                                VStack(alignment: .leading) {
                                    Text(item.app.name).font(.headline)
                                    Text("Готово к установке — откройте «Менеджер»").font(.subheadline).foregroundStyle(.secondary)
                                }
                            } icon: {
                                Image(systemName: "arrow.down.app.fill").foregroundStyle(AppPalette.success)
                            }
                            .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                                Button(role: .destructive) {
                                    withAnimation { installations?.dismissNotification(item.id) }
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
                            withAnimation { installations?.clearNotifications(ready.map(\.id)) }
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

    /// Ready-to-install installations the user has not cleared.
    private var ready: [InstallationDTO] {
        library
            .map { installations?.installations[$0.app.id] ?? $0 }
            .filter { $0.status == "READY_TO_INSTALL" && installations?.isNotificationDismissed($0.id) != true }
    }
}
