import SwiftUI

/// iOS relaunches the app in the background to deliver finished import chunks. The background
/// session is connected here, before SwiftUI builds `ImportCenter`; results that arrive first are
/// buffered by the uploader until `ImportCenter` attaches.
final class StorefrontAppDelegate: NSObject, UIApplicationDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        if StorefrontApp.usesBackgroundUploads {
            BackgroundChunkUploader.shared.connect()
        }
        return true
    }

    func application(_ application: UIApplication, handleEventsForBackgroundURLSession identifier: String, completionHandler: @escaping () -> Void) {
        BackgroundChunkUploader.shared.handleEvents(for: identifier, completion: completionHandler)
    }
}

@main
struct StorefrontApp: App {
    @UIApplicationDelegateAdaptor(StorefrontAppDelegate.self) private var appDelegate
    private let apiClient: APIClient
    @StateObject private var session: SessionStore
    @StateObject private var router: AppRouter
    @StateObject private var installations: InstallationCoordinator
    @StateObject private var imports: ImportCenter
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
        _session = StateObject(wrappedValue: SessionStore(api: apiClient))
        let router = AppRouter()
        _router = StateObject(wrappedValue: router)
        // Local notifications only (no push). Created at launch so that a tap on a
        // «готово к установке» notification that starts the app still opens «Менеджер»;
        // permission is asked later, when the first installation starts.
        let notifier = SystemInstallNotifier()
        notifier.onOpen = { [weak router] in
            router?.sheet = nil
            router?.selectedTab = .manager
        }
        let installations = InstallationCoordinator(
            repository: PreparationRepository(api: apiClient),
            notifier: notifier,
            openURL: { url in await UIApplication.shared.open(url) }
        )
        _installations = StateObject(wrappedValue: installations)
        let live = Self.usesBackgroundUploads
        _imports = StateObject(wrappedValue: ImportCenter(
            repository: ImportRepository(api: apiClient),
            installations: installations,
            uploader: live ? BackgroundChunkUploader.shared : nil,
            store: live ? .standard : nil,
            reachability: live ? NetworkPathReachability.shared : AlwaysOnline()
        ))
    }

    /// Background chunk uploads and saved uploads are off in Debug mock mode (UI tests).
    static var usesBackgroundUploads: Bool {
        #if DEBUG
        APIEnvironment.current().mode != .mock
        #else
        true
        #endif
    }

    var body: some Scene {
        WindowGroup {
            ContentView()
                .tint(AppPalette.accent)
                .environment(\.apiClient, apiClient)
                .environment(\.catalog, CatalogRepository(api: apiClient, cache: .catalog))
                .environmentObject(session)
                .environmentObject(router)
                .environmentObject(installations)
                .environmentObject(imports)
                // Cold launch: validate the stored session without blocking the UI (FULL_PLAN §11),
                // then pick up installations that were in flight when the app was killed.
                .task {
                    await session.restore()
                    await signInAutomaticallyOnce()
                    await installations.resume()
                }
                .onOpenURL { url in
                    // An .ipa another app opened in Ru App Store (iOS copied it to Documents/Inbox).
                    if url.isFileURL {
                        router.selectedTab = .manager
                        imports.incomingFile = url
                        return
                    }
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
        let environment = apiClient.environment
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
        guard let portalURL = try? environment.portalURL,
              let url = await handoff.claimLink(portalURL: portalURL) else { return }
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
