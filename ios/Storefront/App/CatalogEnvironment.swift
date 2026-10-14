import SwiftUI

private struct CatalogKey: EnvironmentKey {
    static let defaultValue = CatalogRepository(api: .configured(), cache: .catalog)
}

extension EnvironmentValues {
    /// Catalog reads, injected once in StorefrontApp.
    var catalog: CatalogRepository {
        get { self[CatalogKey.self] }
        set { self[CatalogKey.self] = newValue }
    }
}
