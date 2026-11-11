import SwiftUI

/// Installs, age, category, size, version, iOS and the last update as a row that scrolls sideways.
struct AppDetailFacts: View {
    let app: StoreApp
    /// Side margin of the page (see `AppDetailLayout.sideInset`).
    let inset: CGFloat
    @ScaledMetric(relativeTo: .caption2) private var minCellWidth = 96.0
    @ScaledMetric(relativeTo: .title3) private var valueHeight = 26.0
    @ScaledMetric(relativeTo: .title3) private var dividerHeight = 40.0

    var body: some View {
        let facts = AppFact.facts(for: app)
        if !facts.isEmpty {
            ScrollView(.horizontal) {
                HStack(alignment: .top, spacing: 0) {
                    ForEach(Array(facts.enumerated()), id: \.element.id) { index, fact in
                        if index > 0 {
                            Divider()
                                .frame(height: dividerHeight)
                                .padding(.top, 6)
                        }
                        cell(fact)
                    }
                }
                .padding(.vertical, 14)
            }
            .scrollIndicators(.hidden)
            .horizontalContentMargins(inset)
            .overlay(alignment: .top) { Divider().padding(.horizontal, inset) }
            .overlay(alignment: .bottom) { Divider().padding(.horizontal, inset) }
        }
    }

    private func cell(_ fact: AppFact) -> some View {
        VStack(spacing: 5) {
            Text(fact.label)
                .textCase(.uppercase)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.tertiary)
            Group {
                if let symbol = fact.symbol {
                    Image(systemName: symbol)
                } else {
                    Text(fact.value ?? "—")
                }
            }
            .font(.title3.weight(.bold))
            .foregroundStyle(.secondary)
            .frame(minHeight: valueHeight)
            if let caption = fact.caption {
                Text(caption)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
        // The strip scrolls, so a cell takes the width its text needs: nothing truncates.
        .fixedSize()
        .frame(minWidth: minCellWidth)
        .padding(.horizontal, 8)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(fact.label)
        .accessibilityValue(fact.spoken)
        .accessibilityIdentifier("fact-\(fact.kind.rawValue)")
    }
}
