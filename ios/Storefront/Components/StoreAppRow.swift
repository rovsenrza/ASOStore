import SwiftUI

/// One app in a list: icon, name, subtitle and the install capsule.
struct StoreAppRow: View {
    let app: StoreApp
    var iconSize: Double = 62

    var body: some View {
        HStack(spacing: 14) {
            NavigationLink(value: app) {
                HStack(spacing: 14) {
                    AppIconView(app: app, size: iconSize)
                        .overlay {
                            RoundedRectangle(cornerRadius: iconSize * 0.22)
                                .strokeBorder(AppPalette.separator.opacity(0.4), lineWidth: 0.5)
                        }

                    VStack(alignment: .leading, spacing: 3) {
                        Text(app.name)
                            .font(.body.weight(.medium))
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
            .buttonStyle(.plain)

            AppActionButton(app: app)
        }
        .frame(minHeight: iconSize + 16)
        .accessibilityElement(children: .contain)
    }
}
