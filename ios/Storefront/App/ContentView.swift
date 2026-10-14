import SwiftUI

struct ContentView: View {
    @EnvironmentObject private var installations: InstallationCoordinator

    var body: some View {
        StorefrontTabView()
            .alert(
                "Не удалось установить",
                isPresented: Binding(get: { installations.lastError != nil }, set: { if !$0 { installations.dismissError() } }),
                presenting: installations.lastError
            ) { _ in
                Button("OK", role: .cancel) {}
            } message: { error in
                Text(InstallationCoordinator.message(for: error))
            }
    }
}

#Preview {
    ContentView()
        .previewEnvironment()
}
