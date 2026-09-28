import SwiftUI

struct ManagerImportCard: View {
    let symbols: [String]
    let highlightedSymbol: Int
    let title: LocalizedStringKey
    let description: LocalizedStringKey

    var body: some View {
        VStack(spacing: AppSpacing.standard) {
            HStack(spacing: 10) {
                ForEach(symbols.indices, id: \.self) { index in
                    if index > symbols.startIndex {
                        Rectangle()
                            .fill(.tertiary)
                            .frame(maxWidth: 42)
                            .frame(height: 1)
                            .accessibilityHidden(true)
                    }

                    Image(systemName: symbols[index])
                        .font(.title2)
                        .symbolRenderingMode(.hierarchical)
                        .foregroundStyle(index == highlightedSymbol ? AppPalette.accent : .primary)
                        .frame(width: 44, height: 44)
                        .background(index == highlightedSymbol ? AnyShapeStyle(AppPalette.ctaFill) : AnyShapeStyle(.clear), in: RoundedRectangle(cornerRadius: 12))
                        .accessibilityHidden(true)
                }
            }

            VStack(spacing: AppSpacing.compact) {
                Text(title)
                    .font(.headline)
                    .multilineTextAlignment(.center)

                Text(description)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, AppSpacing.standard)
        .padding(.vertical, AppSpacing.section)
        .glassCard()
        .accessibilityElement(children: .combine)
    }
}

#Preview {
    ManagerImportCard(
        symbols: ["iphone", "folder.fill", "square.and.arrow.up"],
        highlightedSymbol: 1,
        title: "Импортировать IPA с устройства",
        description: "Выберите IPA в Файлах или отправьте его из другого приложения."
    )
    .padding()
    .background { BrandBackdrop() }
    .background(AppPalette.canvas)
}
