import SwiftUI

/// One app in a list: icon, name, subtitle and the install capsule. The icon is what
/// the app page zooms out of.
struct StoreAppRow: View {
    let app: StoreApp
    /// Names the list, so the same app in two lists zooms from the right one.
    var source = "list"
    var iconSize: Double = 62
    @Environment(\.zoomNamespace) private var zoom

    private var route: AppRoute { AppRoute(app, from: source) }

    var body: some View {
        HStack(spacing: 14) {
            NavigationLink(value: route) {
                HStack(spacing: 14) {
                    AppIconView(app: app, size: iconSize)
                        .overlay {
                            RoundedRectangle(cornerRadius: iconSize * 0.22, style: .continuous)
                                .strokeBorder(AppPalette.separator.opacity(0.4), lineWidth: 0.5)
                        }
                        .zoomSource(route, in: zoom)

                    VStack(alignment: .leading, spacing: 3) {
                        Text(app.name)
                            .font(.body.weight(.semibold))
                            .foregroundStyle(.primary)
                            .lineLimit(2)
                        Text(app.subtitle.isEmpty ? app.category : app.subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(PressableStyle(scale: 0.98))

            AppActionButton(app: app)
        }
        .frame(minHeight: iconSize + 16)
        .accessibilityElement(children: .contain)
    }
}
