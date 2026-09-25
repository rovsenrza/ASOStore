import SwiftUI

extension EnvironmentValues {
    /// Catalog reads, injected once in StorefrontApp.
    @Entry var catalog: CatalogRepository = CatalogRepository(api: .configured(), cache: .catalog)
}
