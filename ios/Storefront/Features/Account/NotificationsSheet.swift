import SwiftUI

/// The bell: apps whose build for this iPhone is ready. These are the only
/// notifications the app has; nothing is invented to fill the list.
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
                    List(ready) { item in
                        Label {
                            VStack(alignment: .leading) {
                                Text(item.app.name).font(.headline)
                                Text("Готово к установке — откройте «Менеджер»").font(.subheadline).foregroundStyle(.secondary)
                            }
                        } icon: {
                            Image(systemName: "arrow.down.app.fill").foregroundStyle(AppPalette.success)
                        }
                    }
                }
            }
            .navigationTitle("Уведомления")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { dismiss() } label: { Image(systemName: "xmark") }
                        .accessibilityLabel("Закрыть")
                }
            }
            .task { library = (try? await PreparationRepository(api: api).library()) ?? [] }
        }
    }

    private var ready: [InstallationDTO] {
        library.map { installations?.installations[$0.app.id] ?? $0 }.filter { $0.status == "READY_TO_INSTALL" }
    }
}
