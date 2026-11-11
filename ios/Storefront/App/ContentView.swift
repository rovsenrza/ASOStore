import SwiftUI

struct ContentView: View {
    @EnvironmentObject private var installations: InstallationCoordinator
    @EnvironmentObject private var router: AppRouter
    @EnvironmentObject private var session: SessionStore
    @Environment(\.scenePhase) private var scenePhase
    @State private var grace = BackgroundGrace()
    /// Polling is only paused in the background, so only a return from there resumes it
    /// (the launch itself is handled by StorefrontApp, after the session is restored).
    @State private var wasInBackground = false

    var body: some View {
        StorefrontTabView()
            .alert(
                "Не удалось установить",
                isPresented: Binding(get: { installations.lastError != nil }, set: { if !$0 { installations.dismissError() } }),
                presenting: installations.lastError
            ) { error in
                if error.code == .unauthenticated || error.code == .sessionExpired {
                    Button("Войти") { router.sheet = .account }
                    Button("Не сейчас", role: .cancel) {}
                } else {
                    Button("OK", role: .cancel) {}
                }
            } message: { error in
                Text(InstallationCoordinator.message(for: error))
            }
            .onChangeCompat(of: scenePhase) { phase in
                switch phase {
                case .background:
                    wasInBackground = true
                    // Installations are not polled in the background, except for a moment
                    // while one is still being prepared.
                    if installations.enterBackground() {
                        grace.begin(isNeeded: { installations.hasPreparationInFlight }) {
                            installations.pausePolling()
                        }
                    }
                case .active:
                    guard wasInBackground else { return }
                    wasInBackground = false
                    grace.cancel()
                    Task { await installations.becomeActive() }
                default:
                    break
                }
            }
            // Signing in makes installations visible again that polling gave up on while signed out.
            .onChangeCompat(of: session.user?.id) { id in
                guard id != nil else { return }
                Task { await installations.resume() }
            }
    }
}

#Preview {
    ContentView()
        .previewEnvironment()
}
