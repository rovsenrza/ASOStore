import SwiftUI

/// Top of the app page: icon, name, subtitle and publisher, then the install action with
/// sharing next to it.
struct AppDetailHeader: View {
    let app: StoreApp
    @Environment(\.dynamicTypeSize) private var typeSize
    @ScaledMetric(relativeTo: .headline) private var scaledShareSize = 52.0

    static let iconSize = 108.0

    /// Matches the height of the install button beside it; grows a little with the text size.
    private var shareSize: CGFloat { min(max(scaledShareSize, 52), 68) }

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            // At accessibility text sizes the titles go under the icon and get the full width.
            let layout = typeSize.isAccessibilitySize
                ? AnyLayout(VStackLayout(alignment: .leading, spacing: 14))
                : AnyLayout(HStackLayout(alignment: .bottom, spacing: 16))
            layout {
                AppIconView(app: app, size: Self.iconSize)
                    .overlay(RoundedRectangle(cornerRadius: Self.iconSize * 0.22, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.35), lineWidth: 0.5))
                    .shadow(color: .black.opacity(0.18), radius: 14, y: 6)
                titles
            }

            HStack(spacing: 12) {
                AppActionButton(app: app, style: .prominent)
                if let shareURL = app.shareURL {
                    ShareLink(item: shareURL, preview: SharePreview(app.name)) {
                        Label("Поделиться", systemImage: "square.and.arrow.up")
                            .labelStyle(.iconOnly)
                            .font(.headline)
                            .foregroundStyle(AppPalette.accent)
                            .frame(width: shareSize, height: shareSize)
                            .glassCircle()
                    }
                    .buttonStyle(PressableStyle(scale: 0.94))
                    .accessibilityHint("Отправить ссылку на приложение")
                    .accessibilityIdentifier("detail-share")
                }
            }
        }
    }

    private var titles: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(app.name)
                .font(.title2.bold())
                .accessibilityAddTraits(.isHeader)
            if !app.subtitle.isEmpty {
                Text(app.subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            if !app.developer.isEmpty {
                Text(app.developer)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(app.hasKnownDeveloper ? AnyShapeStyle(AppPalette.accent) : AnyShapeStyle(.secondary))
            }
        }
        // Wrap instead of truncating: the name is the one thing the page must show in full.
        .fixedSize(horizontal: false, vertical: true)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.bottom, 4)
    }
}
