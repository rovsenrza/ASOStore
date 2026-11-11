import Foundation

/// One released version of an app, as the app page's «История версий» lists it.
struct AppVersion: Identifiable, Hashable {
    let id: String
    let version: String
    var buildNumber: String = ""
    var notes: String = ""
    var releasedAt: Date?
}
