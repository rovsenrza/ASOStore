import SwiftUI

/// Installed and pending apps. Installation state comes from the backend in
/// Phase 6; until then the tab says so instead of showing illustrative data.
struct LibraryView: View {
    var body: some View {
        NavigationStack {
            ContentUnavailableView {
                Label("Здесь появятся ваши приложения", systemImage: "square.stack")
            } description: {
                Text("Приложения, которые вы установите через Storefront, и доступные для них обновления будут показаны на этом экране.")
            }
            .background(AppPalette.canvas)
            .navigationTitle("Медиатека")
        }
    }
}

#Preview {
    LibraryView()
}
