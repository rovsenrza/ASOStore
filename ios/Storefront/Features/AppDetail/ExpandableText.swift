import SwiftUI

/// Body text folded to four lines behind «Ещё». The toggle appears only when the fold really
/// hides something: the full text is measured against the folded one, so a short text never
/// gets a button that does nothing, whatever the text size or window width.
struct ExpandableText: View {
    let text: String
    @State private var isExpanded = false
    @State private var fullHeight: CGFloat?
    @State private var foldedHeight: CGFloat?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private static let foldedLines = 4

    private var canExpand: Bool {
        // Until the first layout pass has measured both, go by the text's length.
        guard let fullHeight, let foldedHeight else { return AppDetailFormat.isLong(text) }
        return fullHeight > foldedHeight + 1
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            styled
                .lineLimit(isExpanded ? nil : Self.foldedLines)
                .frame(maxWidth: .infinity, alignment: .leading)
                .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { height in
                    if !isExpanded { foldedHeight = height }
                }
                .background(alignment: .top) {
                    // The same text at full length, invisible: only its height matters.
                    styled
                        .fixedSize(horizontal: false, vertical: true)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .hidden()
                        .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { fullHeight = $0 }
                }

            if canExpand {
                Button {
                    withAnimation(.detailReveal(reduceMotion: reduceMotion)) { isExpanded.toggle() }
                } label: {
                    HStack(spacing: 4) {
                        Text(isExpanded ? "Свернуть" : "Ещё")
                        Image(systemName: "chevron.down")
                            .font(.caption.weight(.bold))
                            .rotationEffect(.degrees(isExpanded ? 180 : 0))
                            .accessibilityHidden(true)
                    }
                    .font(.body.weight(.medium))
                    .frame(minHeight: 32)
                    .contentShape(.rect)
                }
                .accessibilityHint(isExpanded ? "Свернуть текст" : "Показать текст полностью")
            }
        }
    }

    private var styled: some View {
        Text(text)
            .font(.body)
            .lineSpacing(3)
    }
}
