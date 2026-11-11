import SwiftUI

/// «Информация»: seller, category, size, compatibility, age, last update, installs, version
/// and the listing's links, as one card.
struct AppDetailInfo: View {
    let app: StoreApp
    @Environment(\.dynamicTypeSize) private var typeSize

    var body: some View {
        let rows = AppInfoRow.rows(for: app)
        if !rows.isEmpty {
            VStack(alignment: .leading, spacing: 12) {
                Text("Информация")
                    .detailSectionTitle()
                VStack(spacing: 0) {
                    ForEach(Array(rows.enumerated()), id: \.element.id) { index, row in
                        if index > 0 {
                            Divider()
                        }
                        if let url = row.url {
                            link(row, url: url)
                        } else {
                            fact(row)
                        }
                    }
                }
                .padding(.horizontal, 16)
                .background(AppPalette.elevated, in: .rect(cornerRadius: 20, style: .continuous))
            }
        }
    }

    private func fact(_ row: AppInfoRow) -> some View {
        // Side by side normally; stacked at accessibility sizes, where two columns leave
        // a few characters per line.
        let layout = typeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 2))
            : AnyLayout(HStackLayout(alignment: .firstTextBaseline, spacing: 16))
        return layout {
            Text(row.title)
                .foregroundStyle(.secondary)
            if !typeSize.isAccessibilitySize {
                Spacer(minLength: 0)
            }
            Text(row.value)
                .multilineTextAlignment(typeSize.isAccessibilitySize ? .leading : .trailing)
        }
        .font(.subheadline)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, 13)
        .accessibilityElement(children: .combine)
    }

    private func link(_ row: AppInfoRow, url: URL) -> some View {
        Link(destination: url) {
            HStack(spacing: 12) {
                Text(row.title)
                    .foregroundStyle(.primary)
                    .multilineTextAlignment(.leading)
                Spacer(minLength: 0)
                Image(systemName: row.symbol)
                    .font(.caption.bold())
                    .foregroundStyle(AppPalette.accent)
                    .accessibilityHidden(true)
            }
            .font(.subheadline)
            .padding(.vertical, 13)
            .contentShape(.rect)
        }
        .accessibilityHint(url.scheme == "mailto" ? "Написать письмо" : "Откроется в браузере")
    }
}
