enum StorefrontTab: String, Hashable {
    case home
    /// Every app in one list, narrowed by kind (games or apps), category and order.
    case catalog
    case manager
    case search
}

/// Sheets opened from the header on every tab.
enum StoreSheet: String, Identifiable {
    case account
    case notifications

    var id: String { rawValue }
}
