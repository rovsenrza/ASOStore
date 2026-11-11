import SwiftUI

/// One app in a shelf on the app page: icon, name, subtitle and the install capsule.
/// The icon and texts open that app's page; the capsule installs.
struct AppShelfCard: View {
    let app: StoreApp
    var source = "detail-related"
    @Environment(\.zoomNamespace) private var zoom
    @Environment(\.dynamicTypeSize) private var typeSize
    @ScaledMetric(relativeTo: .subheadline) private var scaledWidth = 132.0

    private static let iconSize = 84.0

    private var route: AppRoute { AppRoute(app, from: source) }
    /// Wide enough for a word or two per line of the name, whatever the text size.
    private var width: CGFloat { min(max(scaledWidth, 132), 230) }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            NavigationLink(value: route) {
                VStack(alignment: .leading, spacing: 8) {
                    AppIconView(app: app, size: Self.iconSize)
                        .overlay {
                            RoundedRectangle(cornerRadius: Self.iconSize * 0.22, style: .continuous)
                                .strokeBorder(AppPalette.separator.opacity(0.4), lineWidth: 0.5)
                        }
                        .zoomSource(route, in: zoom)

                    ZStack(alignment: .topLeading) {
                        // Every card keeps room for the longest name and subtitle, so the capsules
                        // line up along the shelf while each card's text stays together at the top.
                        texts(name: " ", subtitle: " ", reservesSpace: true)
                            .hidden()
                        texts(name: app.name, subtitle: app.subtitle.isEmpty ? app.category : app.subtitle, reservesSpace: false)
                    }
                }
                .frame(width: width, alignment: .leading)
                .contentShape(.rect)
            }
            .buttonStyle(PressableStyle(scale: 0.97))
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(app.name)
            .accessibilityValue(app.subtitle.isEmpty ? app.category : app.subtitle)
            .accessibilityHint("Открыть страницу приложения")
            .accessibilityAddTraits(.isButton)

            AppActionButton(app: app)
        }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("shelf-card-\(app.id)")
    }

    private func texts(name: String, subtitle: String, reservesSpace: Bool) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(name)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.primary)
                // Two lines are held on every card; at accessibility sizes a long name may take a third.
                .lineLimit(typeSize.isAccessibilitySize && !reservesSpace ? 3 : 2, reservesSpace: reservesSpace)
            Text(subtitle)
                .font(.caption)
                .foregroundStyle(.secondary)
                .lineLimit(2, reservesSpace: reservesSpace)
        }
        .multilineTextAlignment(.leading)
    }
}
