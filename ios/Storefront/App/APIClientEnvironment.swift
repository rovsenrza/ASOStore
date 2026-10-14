import SwiftUI

private struct APIClientKey: EnvironmentKey {
    static let defaultValue: APIClient = .configured()
}

extension EnvironmentValues {
    /// Shared API client, injected once in StorefrontApp.
    var apiClient: APIClient {
        get { self[APIClientKey.self] }
        set { self[APIClientKey.self] = newValue }
    }
}
