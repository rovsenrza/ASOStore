import SwiftUI

/// «История версий»: earlier versions with their dates and notes, folded until asked for.
struct VersionHistory: View {
    let versions: [AppVersion]
    @State private var isExpanded = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Button {
                withAnimation(.detailReveal(reduceMotion: reduceMotion)) { isExpanded.toggle() }
            } label: {
                HStack(spacing: 8) {
                    Text("История версий")
                        .font(.body.weight(.medium))
                        .multilineTextAlignment(.leading)
                    Spacer(minLength: 8)
                    Image(systemName: "chevron.down")
                        .font(.caption.weight(.bold))
                        .rotationEffect(.degrees(isExpanded ? 180 : 0))
                        .accessibilityHidden(true)
                }
                .padding(.vertical, 13)
                .contentShape(.rect)
            }
            .accessibilityValue(isExpanded ? "Развёрнуто" : "Свёрнуто")
            .accessibilityIdentifier("detail-version-history")

            if isExpanded {
                ForEach(versions) { version in
                    Divider()
                    row(version)
                }
            }
        }
        .padding(.horizontal, 16)
        .background(AppPalette.elevated, in: .rect(cornerRadius: 20, style: .continuous))
    }

    private func row(_ version: AppVersion) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            // Version and date side by side, or stacked when the text is too large for one line.
            ViewThatFits(in: .horizontal) {
                HStack(alignment: .firstTextBaseline) {
                    title(version)
                    Spacer(minLength: 12)
                    date(version)
                }
                VStack(alignment: .leading, spacing: 2) {
                    title(version)
                    date(version)
                }
            }
            if version.notes.isEmpty {
                Text("Без описания изменений")
                    .font(.subheadline)
                    .foregroundStyle(.tertiary)
            } else {
                Text(version.notes)
                    .font(.subheadline)
                    .lineSpacing(2)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, 13)
        .accessibilityElement(children: .combine)
    }

    private func title(_ version: AppVersion) -> some View {
        Text("Версия \(version.version)")
            .font(.subheadline.weight(.semibold))
    }

    @ViewBuilder
    private func date(_ version: AppVersion) -> some View {
        if let releasedAt = version.releasedAt {
            Text(AppDetailFormat.fullDate(releasedAt))
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
    }
}
