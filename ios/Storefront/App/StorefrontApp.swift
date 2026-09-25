import SwiftUI

@main
struct StorefrontApp: App {
    private let apiClient: APIClient
    @State private var session: SessionStore
    @State private var router = AppRouter()

    init() {
        #if DEBUG
        // UI tests start from an empty offline cache.
        if ProcessInfo.processInfo.arguments.contains("-resetCache") {
            ResponseCache.catalog.clear()
        }
        #endif
        let apiClient = APIClient.configured()
        self.apiClient = apiClient
        _session = State(initialValue: SessionStore(api: apiClient))
    }

    var body: some Scene {
        WindowGroup {
            ContentView()
                .tint(AppPalette.accent)
                .environment(\.apiClient, apiClient)
                .environment(\.catalog, CatalogRepository(api: apiClient, cache: .catalog))
                .environment(session)
                .environment(router)
                // Cold launch: validate the stored session without blocking the UI (FULL_PLAN §11).
                .task { await session.restore() }
                .onOpenURL { url in
                    Task { await router.handle(url, session: session) }
                }
        }
    }
}
