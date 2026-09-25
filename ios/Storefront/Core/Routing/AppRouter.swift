import Foundation
import Observation

/// App-wide navigation state: the selected tab and deep-link outcomes.
@Observable
final class AppRouter {
    var selectedTab: StorefrontTab
    /// Shown as an alert when a deep link cannot be completed.
    var alertMessage: String?

    init(arguments: [String] = ProcessInfo.processInfo.arguments) {
        var tab = StorefrontTab.today
        #if DEBUG
        if let index = arguments.firstIndex(of: "-demoTab"),
           arguments.indices.contains(index + 1),
           let requested = StorefrontTab(rawValue: arguments[index + 1]) {
            tab = requested
        }
        #endif
        selectedTab = tab
    }

    func handle(_ url: URL, session: SessionStore) async {
        guard let link = DeepLink(url: url) else { return }

        switch link {
        case .claim(let code):
            selectedTab = .account
            do {
                try await session.redeemClaim(code: code)
            } catch let error as APIError {
                alertMessage = error.code == .claimInvalid
                    ? "Ссылка для входа устарела или уже использована. Откройте её заново на сайте."
                    : "Не удалось войти по ссылке. Проверьте интернет и попробуйте ещё раз."
            } catch {
                alertMessage = "Не удалось войти по ссылке."
            }
        case .app:
            // App pages load from the API from Phase 4; until then open the catalog.
            selectedTab = .apps
        }
    }
}
