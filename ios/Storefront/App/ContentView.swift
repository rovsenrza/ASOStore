import SwiftUI

struct ContentView: View {
    var body: some View {
        StorefrontTabView()
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    ContentView()
        .environment(SessionStore(api: api))
        .environment(AppRouter())
        .environment(\.apiClient, api)
}
