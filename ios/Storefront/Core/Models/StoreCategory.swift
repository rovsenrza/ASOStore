import Foundation

struct StoreCategory: Identifiable, Hashable {
    let id: String
    let title: String
    let subtitle: String
    let systemImage: String
    let artwork: AppArtwork
    /// APPS or GAMES, when the API says.
    var kind: String?
    var appCount: Int?
}
