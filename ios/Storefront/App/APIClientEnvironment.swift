import SwiftUI

extension EnvironmentValues {
    /// Shared API client, injected once in StorefrontApp.
    @Entry var apiClient: APIClient = .configured()
}
