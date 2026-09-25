import SwiftUI

struct StorefrontTabView: View {
    @Environment(SessionStore.self) private var session
    @Environment(AppRouter.self) private var router
    @State private var showsExpiredAlert = false

    var body: some View {
        @Bindable var router = router

        TabView(selection: $router.selectedTab) {
            Tab("Главная", systemImage: "rectangle.stack.fill", value: .home) {
                FeedScreen(kind: .all)
            }

            Tab("Игры", systemImage: "gamecontroller.fill", value: .games) {
                FeedScreen(kind: .games)
            }

            Tab("Приложения", systemImage: "square.stack.3d.up.fill", value: .apps) {
                FeedScreen(kind: .apps)
            }

            Tab("Менеджер", systemImage: "arrow.down.app.fill", value: .manager) {
                ManagerView()
            }

            Tab("Поиск", systemImage: "magnifyingglass", value: .search, role: .search) {
                SearchView()
            }
        }
        .sheet(item: $router.sheet) { sheet in
            switch sheet {
            case .account: AccountSheet()
            case .notifications: NotificationsSheet()
            }
        }
        .onChange(of: session.state) { _, state in
            showsExpiredAlert = state == .expired
        }
        .alert("Сеанс истёк", isPresented: $showsExpiredAlert) {
            Button("Войти") { router.sheet = .account }
            Button("Позже", role: .cancel) {}
        } message: {
            Text("Войдите снова, чтобы продолжить работу с магазином.")
        }
        .alert("Вход по ссылке", isPresented: Binding(
            get: { router.alertMessage != nil },
            set: { if !$0 { router.alertMessage = nil } }
        )) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(router.alertMessage ?? "")
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    StorefrontTabView()
        .environment(SessionStore(api: api))
        .environment(AppRouter())
        .environment(\.apiClient, api)
}
