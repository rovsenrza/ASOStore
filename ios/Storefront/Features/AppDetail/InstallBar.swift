import SwiftUI

/// Pinned to the bottom once the header scrolls away: the app and its install action on glass.
struct InstallBar: View {
    let app: StoreApp
    @Environment(\.dynamicTypeSize) private var typeSize

    var body: some View {
        HStack(spacing: 12) {
            AppIconView(app: app, size: 40)
            VStack(alignment: .leading, spacing: 1) {
                Text(app.name)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(typeSize.isAccessibilitySize ? 2 : 1)
                // The bar stays compact: at the largest text sizes the name alone is enough.
                if !app.developer.isEmpty, !typeSize.isAccessibilitySize {
                    Text(app.developer)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .accessibilityElement(children: .combine)
            AppActionButton(app: app)
        }
        .padding(.leading, 10)
        .padding(.trailing, 8)
        .padding(.vertical, 8)
        .glassCard(cornerRadius: 26)
        .frame(maxWidth: AppDetailLayout.readingWidth)
        .frame(maxWidth: .infinity)
    }
}
