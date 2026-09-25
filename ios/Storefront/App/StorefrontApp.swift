import SwiftUI

@main
struct StorefrontApp: App {
    private let apiClient: APIClient
    @State private var session: SessionStore
    @State private var router = AppRouter()
    @State private var installations: InstallationCoordinator

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
        _installations = State(initialValue: InstallationCoordinator(
            repository: PreparationRepository(api: apiClient),
            openURL: { url in await UIApplication.shared.open(url) }
        ))
    }

    var body: some Scene {
        WindowGroup {
            ContentView()
                .tint(AppPalette.accent)
                .environment(\.apiClient, apiClient)
                .environment(\.catalog, CatalogRepository(api: apiClient, cache: .catalog))
                .environment(session)
                .environment(router)
                .environment(installations)
                // Cold launch: validate the stored session without blocking the UI (FULL_PLAN §11),
                // then pick up installations that were in flight when the app was killed.
                .task {
                    await session.restore()
                    await installations.resume()
                }
                .onOpenURL { url in
                    Task { await router.handle(url, session: session) }
                }
        }
    }
}
