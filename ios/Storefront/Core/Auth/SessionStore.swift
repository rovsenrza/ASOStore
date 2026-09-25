import Foundation
import Observation
import UIKit

/// The signed-in customer, if any (FULL_PLAN §11 "SessionStore").
///
/// Cold launch restores the session in the background: the UI never waits
/// on it, and a slow or offline network leaves the stored tokens in place.
@Observable
final class SessionStore {
    enum State: Equatable {
        case restoring
        case signedOut
        /// The server ended the session (refresh token rejected); sign in again.
        case expired
        /// Tokens exist but the server could not be reached to confirm them.
        case unverified
        case signedIn(MeDTO)
    }

    private(set) var state: State = .restoring
    let api: APIClient

    init(api: APIClient) {
        self.api = api
        Task { [weak self, events = api.authenticator.events] in
            for await event in events where event == .expired {
                self?.state = .expired
            }
        }
    }

    var user: MeDTO? {
        if case let .signedIn(user) = state { user } else { nil }
    }

    func restore() async {
        guard await api.authenticator.hasTokens else {
            state = .signedOut
            return
        }

        do {
            state = .signedIn(try await api.get("/auth/me", as: MeDTO.self).data)
        } catch let error as APIError where error.code == .sessionExpired || error.code == .unauthenticated {
            state = .expired
        } catch {
            state = .unverified
        }
    }

    func signIn(email: String, password: String) async throws {
        let request = TokenRequest(
            email: email.trimmingCharacters(in: .whitespaces),
            password: password,
            deviceName: UIDevice.current.model
        )
        let pair = try await api.post("/auth/tokens", body: request, as: TokenPairDTO.self).data
        await api.authenticator.update(StoredTokens(pair))
        state = .signedIn(pair.user)
    }

    /// Signs in with the one-time code from storefront://claim (IMPLEMENTATION_PLAN G8).
    /// The tokens stay bound to the device the customer enrolled on the web.
    func redeemClaim(code: String) async throws {
        let request = ClaimRedeemRequest(code: code, deviceName: UIDevice.current.model)
        let claim = try await api.post("/storefront/claims/redeem", body: request, as: ClaimRedeemDTO.self).data
        await api.authenticator.update(StoredTokens(claim))
        state = .signedIn(claim.user)
    }

    func refreshUser() async {
        if let user = try? await api.get("/auth/me", as: MeDTO.self).data {
            state = .signedIn(user)
        }
    }

    func signOut() async {
        // Best effort: the local tokens are dropped even if the server is unreachable.
        _ = try? await api.post("/auth/logout", as: NoContent?.self)
        await api.authenticator.update(nil)
        state = .signedOut
    }
}
