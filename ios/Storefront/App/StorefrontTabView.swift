import SwiftUI

struct StorefrontTabView: View {
    @Environment(SessionStore.self) private var session
    @Environment(AppRouter.self) private var router
    @State private var showsExpiredAlert = false

    var body: some View {
        @Bindable var router = router

        TabView(selection: $router.selectedTab) {
            Tab("Главная", systemImage: "sparkles.rectangle.stack.fill", value: .home) {
                FeedScreen()
            }

            Tab("Каталог", systemImage: "square.grid.2x2.fill", value: .catalog) {
                CatalogView()
            }

            Tab("Менеджер", systemImage: "arrow.down.app.fill", value: .manager) {
                ManagerView()
            }

            Tab("Поиск", systemImage: "magnifyingglass", value: .search, role: .search) {
                SearchView()
            }
        }
        .modifier(MinimizingTabBar())
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

/// iOS 26: the tab bar shrinks while scrolling down, giving the list the screen.
private struct MinimizingTabBar: ViewModifier {
    func body(content: Content) -> some View {
        if #available(iOS 26, *) {
            content.tabBarMinimizeBehavior(.onScrollDown)
        } else {
            content
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
