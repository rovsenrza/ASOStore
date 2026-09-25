import SwiftUI

/// Placeholder colour for apps and categories without artwork.
enum AppArtwork: Hashable, CaseIterable {
    case blue
    case indigo
    case orange
    case green
    case pink
    case purple
    case cyan
    case red
    case graphite

    var color: Color {
        switch self {
        case .blue: .blue
        case .indigo: .indigo
        case .orange: .orange
        case .green: .green
        case .pink: .pink
        case .purple: .purple
        case .cyan: .cyan
        case .red: .red
        case .graphite: Color(white: 0.18)
        }
    }

    /// A stable colour for an identifier (String.hashValue changes between launches).
    static func derived(from identifier: String) -> AppArtwork {
        let sum = identifier.unicodeScalars.reduce(0) { $0 &+ Int($1.value) }
        return allCases[sum % allCases.count]
    }
}
