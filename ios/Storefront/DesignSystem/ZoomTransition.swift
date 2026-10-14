import SwiftUI

/// Opening an app page: which app, and which on-screen element it grows out of. The same
/// app can be on screen twice (a shelf and «Все приложения»), so the source names the place.
struct AppRoute: Hashable {
    let app: StoreApp
    let source: String

    init(_ app: StoreApp, from source: String) {
        self.app = app
        self.source = source
    }

    var zoomID: String { "\(source)/\(app.id)" }

    /// Opened from a link: there is no element to zoom out of.
    static let link = "link"
}

private struct ZoomNamespaceKey: EnvironmentKey {
    static let defaultValue: Namespace.ID? = nil
}

extension EnvironmentValues {
    /// The tab's namespace for zoom transitions into app pages.
    var zoomNamespace: Namespace.ID? {
        get { self[ZoomNamespaceKey.self] }
        set { self[ZoomNamespaceKey.self] = newValue }
    }
}

extension View {
    /// Marks the element an app page zooms out of.
    @ViewBuilder
    func zoomSource(_ route: AppRoute, in namespace: Namespace.ID?) -> some View {
        if #available(iOS 18, *), let namespace {
            matchedTransitionSource(id: route.zoomID, in: namespace)
        } else {
            self
        }
    }

    /// The app page grows out of its source.
    @ViewBuilder
    func zoomDestination(_ route: AppRoute, in namespace: Namespace.ID?) -> some View {
        if #available(iOS 18, *), let namespace, route.source != AppRoute.link {
            navigationTransition(.zoom(sourceID: route.zoomID, in: namespace))
        } else {
            self
        }
    }
}
