import SwiftUI

struct StoreAppRow: View {
    let app: StoreApp

    var body: some View {
        HStack(spacing: AppSpacing.standard) {
            NavigationLink(value: app) {
                HStack(spacing: AppSpacing.standard) {
                    AppIconView(app: app, size: 64)

                    VStack(alignment: .leading, spacing: AppSpacing.compact / 2) {
                        Text(app.name)
                            .font(.headline)
                            .foregroundStyle(.primary)

                        Text(app.subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)

                        Text(app.category)
                            .font(.footnote)
                            .foregroundStyle(.tertiary)
                    }
                }
            }
            .buttonStyle(.plain)

            Spacer(minLength: AppSpacing.compact)

            AppActionButton(app: app)
        }
        .frame(minHeight: 76)
        .accessibilityElement(children: .contain)
    }
}
