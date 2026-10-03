import SwiftUI

@main
struct StorefrontApp: App {
    private let apiClient: APIClient
    @State private var session: SessionStore
    @State private var router = AppRouter()
    @State private var installations: InstallationCoordinator
    private let handoff = PortalHandoff()

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
                    await signInAutomaticallyOnce()
                    await installations.resume()
                }
                .onOpenURL { url in
                    Task { await router.handle(url, session: session) }
                }
        }
    }

    /// First launch after installing from enrollment: sign in with no taps.
    ///
    /// This build was signed for one enrolled device and carries a one-time, device-bound login
    /// code in its Info.plist (StorefrontBootstrapClaim); redeeming it opens the app already signed
    /// in. If there is no code, or it has expired, fall back to the Safari handoff (one tap).
    private func signInAutomaticallyOnce() async {
        let environment = await apiClient.environment
        guard environment.mode == .live, session.state == .signedOut else { return }

        if let code = Self.embeddedBootstrapClaim, !Self.bootstrapClaimRedeemed {
            // One-time: don't retry a consumed code on the next launch.
            Self.bootstrapClaimRedeemed = true
            do {
                try await session.redeemClaim(code: code)
                return
            } catch {
                // Expired or already used: fall through to the handoff.
            }
        }

        guard !PortalHandoff.attemptedAutomatically else { return }
        PortalHandoff.attemptedAutomatically = true
        guard let url = await handoff.claimLink(portalURL: environment.portalURL) else { return }
        await router.handle(url, session: session)
    }

    /// The one-time code embedded in this build at signing (nil for a build signed without one).
    private static var embeddedBootstrapClaim: String? {
        (Bundle.main.object(forInfoDictionaryKey: "StorefrontBootstrapClaim") as? String).flatMap { $0.isEmpty ? nil : $0 }
    }

    private static var bootstrapClaimRedeemed: Bool {
        get { UserDefaults.standard.bool(forKey: "bootstrapClaimRedeemed") }
        set { UserDefaults.standard.set(newValue, forKey: "bootstrapClaimRedeemed") }
    }
}
