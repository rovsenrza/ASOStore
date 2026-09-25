import SwiftUI

struct ContentView: View {
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?

    var body: some View {
        StorefrontTabView()
            .alert(
                "Не удалось установить",
                isPresented: Binding(get: { installations?.lastError != nil }, set: { if !$0 { installations?.dismissError() } }),
                presenting: installations?.lastError
            ) { _ in
                Button("OK", role: .cancel) {}
            } message: { error in
                Text(InstallationCoordinator.message(for: error))
            }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    ContentView()
        .environment(SessionStore(api: api))
        .environment(AppRouter())
        .environment(\.apiClient, api)
}
