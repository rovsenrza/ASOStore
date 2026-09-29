import Foundation
import Observation

/// App-wide navigation state: the selected tab, the header sheets and deep-link outcomes.
@Observable
final class AppRouter {
    var selectedTab: StorefrontTab
    /// Account or notifications, opened from the header capsule.
    var sheet: StoreSheet?
    /// Shown as an alert when a deep link cannot be completed.
    var alertMessage: String?
    /// An app page a link asked for; Каталог opens it and clears this.
    var requestedAppID: String?

    init(arguments: [String] = ProcessInfo.processInfo.arguments) {
        var tab = StorefrontTab.home
        var sheet: StoreSheet?
        var appID: String?
        #if DEBUG
        if let index = arguments.firstIndex(of: "-demoApp"), arguments.indices.contains(index + 1) {
            tab = .catalog
            appID = arguments[index + 1]
        }
        if let index = arguments.firstIndex(of: "-demoTab"), arguments.indices.contains(index + 1) {
            let requested = arguments[index + 1]
            if let requestedTab = StorefrontTab(rawValue: requested) {
                tab = requestedTab
            } else {
                sheet = StoreSheet(rawValue: requested)
            }
        }
        #endif
        selectedTab = tab
        self.sheet = sheet
        requestedAppID = appID
    }

    func handle(_ url: URL, session: SessionStore) async {
        guard let link = DeepLink(url: url) else { return }

        switch link {
        case .claim(let code):
            sheet = .account
            do {
                try await session.redeemClaim(code: code)
            } catch let error as APIError {
                alertMessage = error.code == .claimInvalid
                    ? "Ссылка для входа устарела или уже использована. Откройте её заново на сайте."
                    : "Не удалось войти по ссылке. Проверьте интернет и попробуйте ещё раз."
            } catch {
                alertMessage = "Не удалось войти по ссылке."
            }
        case .app(let id):
            selectedTab = .catalog
            requestedAppID = id
        }
    }
}
