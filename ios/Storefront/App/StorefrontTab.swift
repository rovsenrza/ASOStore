enum StorefrontTab: String, Hashable {
    case home
    case games
    case apps
    case manager
    case search
}

/// Sheets opened from the header on every tab.
enum StoreSheet: String, Identifiable {
    case account
    case notifications

    var id: String { rawValue }
}
