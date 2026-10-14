import SwiftUI

extension View {
    /// Everything StorefrontApp injects, backed by mock fixtures. Screens read the shared models
    /// as environment objects, which must be present, so every preview goes through this.
    func previewEnvironment() -> some View {
        let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
        let installations = InstallationCoordinator(repository: PreparationRepository(api: api), openURL: { _ in false })
        return self
            .environment(\.apiClient, api)
            .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
            .environmentObject(SessionStore(api: api))
            .environmentObject(AppRouter())
            .environmentObject(installations)
            .environmentObject(ImportCenter(repository: ImportRepository(api: api), installations: installations))
    }
}
