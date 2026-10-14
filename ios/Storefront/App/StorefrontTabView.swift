import SwiftUI

struct StorefrontTabView: View {
    @EnvironmentObject private var session: SessionStore
    @EnvironmentObject private var router: AppRouter
    @State private var showsExpiredAlert = false
    @State private var showsUpdateSheet = false
    /// Offer the update sheet at most once per launch, even as `session.user` refreshes repeatedly.
    @State private var offeredUpdateOnce = false

    var body: some View {
        tabs
            .modifier(MinimizingTabBar())
            .sheet(item: $router.sheet) { sheet in
                switch sheet {
                case .account: AccountSheet()
                case .notifications: NotificationsSheet()
                }
            }
            .sheet(isPresented: $showsUpdateSheet) {
                if let update = session.user?.appUpdate {
                    UpdateAvailableSheet(update: update)
                }
            }
            .onChangeCompat(of: session.state) { state in
                showsExpiredAlert = state == .expired
            }
            .onChangeCompat(of: session.user?.appUpdate, initial: true) { update in
                guard update != nil, !offeredUpdateOnce else { return }
                offeredUpdateOnce = true
                showsUpdateSheet = true
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

extension StorefrontTabView {
    @ViewBuilder
    private var tabs: some View {
        if #available(iOS 18, *) {
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
        } else {
            // iOS 16–17: the same four tabs without the iOS 18 Tab API.
            TabView(selection: $router.selectedTab) {
                FeedScreen()
                    .tabItem { Label("Главная", systemImage: "sparkles.rectangle.stack.fill") }
                    .tag(StorefrontTab.home)
                CatalogView()
                    .tabItem { Label("Каталог", systemImage: "square.grid.2x2.fill") }
                    .tag(StorefrontTab.catalog)
                ManagerView()
                    .tabItem { Label("Менеджер", systemImage: "arrow.down.app.fill") }
                    .tag(StorefrontTab.manager)
                SearchView()
                    .tabItem { Label("Поиск", systemImage: "magnifyingglass") }
                    .tag(StorefrontTab.search)
            }
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
    StorefrontTabView()
        .previewEnvironment()
}
