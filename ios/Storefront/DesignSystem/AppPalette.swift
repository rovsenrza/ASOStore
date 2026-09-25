import SwiftUI

/// Ru AppStore colours. Brand blues come from the asset catalog and adapt to
/// light and dark mode; everything else uses semantic system colours.
enum AppPalette {
    /// Brand blue (AccentColor): deeper in light mode, brighter in dark mode for contrast.
    static let accent = Color.accentColor
    /// Top-of-screen glow behind the header.
    static let headerGlow = Color(.headerGlow)
    static let brandDeep = Color(.brandDeep)
    static let success = Color.green
    static let canvas = Color(.systemBackground)
    static let elevated = Color(.secondarySystemBackground)
    /// Grouped cards (account sheet, manager rows).
    static let card = Color(.secondarySystemGroupedBackground)
    /// Fill of the install capsule.
    static let ctaFill = Color(.tertiarySystemFill)
    static let separator = Color(.separator)
}
