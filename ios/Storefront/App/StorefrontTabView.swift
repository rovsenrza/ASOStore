import SwiftUI

struct StorefrontTabView: View {
    @Environment(SessionStore.self) private var session
    @Environment(AppRouter.self) private var router
    @State private var showsExpiredAlert = false

    var body: some View {
        @Bindable var router = router

        TabView(selection: $router.selectedTab) {
            Tab("Сегодня", systemImage: "sparkles", value: .today) {
                TodayView()
            }

            Tab("Приложения", systemImage: "square.grid.2x2", value: .apps) {
                BrowseView()
            }

            Tab("Поиск", systemImage: "magnifyingglass", value: .search) {
                SearchView()
            }

            Tab("Медиатека", systemImage: "square.stack", value: .library) {
                LibraryView()
            }

            Tab("Аккаунт", systemImage: "person.crop.circle", value: .account) {
                AccountView()
            }
        }
        .onChange(of: session.state) { _, state in
            showsExpiredAlert = state == .expired
        }
        .alert("Сеанс истёк", isPresented: $showsExpiredAlert) {
            Button("Войти") { router.selectedTab = .account }
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
