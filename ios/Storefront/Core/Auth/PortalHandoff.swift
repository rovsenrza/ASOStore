import AuthenticationServices
import UIKit

/// Signs the app in with the account the customer already uses in Safari.
///
/// The system web sign-in sheet shares Safari's cookies, so the portal knows the
/// customer. `app-signin.html` turns that session into a one-time
/// storefront://claim link, which the sheet hands straight back to the app.
final class PortalHandoff: NSObject, ASWebAuthenticationPresentationContextProviding {
    private static let attemptedKey = "portalHandoffAttempted"

    /// The automatic attempt runs once per install; after that the customer signs in by hand.
    static var attemptedAutomatically: Bool {
        get { UserDefaults.standard.bool(forKey: attemptedKey) }
        set { UserDefaults.standard.set(newValue, forKey: attemptedKey) }
    }

    private var authSession: ASWebAuthenticationSession?

    /// The claim link, or nil when the customer dismissed the sheet or the portal had none.
    func claimLink(portalURL: URL) async -> URL? {
        await withCheckedContinuation { continuation in
            let url = portalURL.appending(path: "app-signin.html")
            let completion: ASWebAuthenticationSession.CompletionHandler = { url, _ in
                continuation.resume(returning: url)
            }
            let session: ASWebAuthenticationSession
            if #available(iOS 17.4, *) {
                session = ASWebAuthenticationSession(url: url, callback: .customScheme("storefront"), completionHandler: completion)
            } else {
                session = ASWebAuthenticationSession(url: url, callbackURLScheme: "storefront", completionHandler: completion)
            }
            session.presentationContextProvider = self
            session.prefersEphemeralWebBrowserSession = false
            authSession = session
            if !session.start() {
                continuation.resume(returning: nil)
            }
        }
    }

    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        UIApplication.shared.connectedScenes
            .compactMap { ($0 as? UIWindowScene)?.keyWindow }
            .first ?? ASPresentationAnchor()
    }
}
