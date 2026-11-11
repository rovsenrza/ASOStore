import SwiftUI

/// The long part of the app page while its detail request is in flight: screenshots,
/// a few lines of description and the information card, as quiet shapes.
struct AppDetailSkeleton: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 28) {
            // The row of screenshots runs off the side like the real gallery; as an overlay it
            // cannot widen the page.
            Color.clear
                .frame(height: 300)
                .overlay(alignment: .leading) {
                    HStack(spacing: 12) {
                        ForEach(0..<3, id: \.self) { _ in
                            RoundedRectangle(cornerRadius: 26, style: .continuous)
                                .frame(width: 236, height: 300)
                        }
                    }
                    .fixedSize()
                }
                .clipped()

            VStack(alignment: .leading, spacing: 12) {
                Capsule().frame(width: 210, height: 20)
                ForEach(0..<4, id: \.self) { line in
                    Capsule()
                        .frame(height: 12)
                        .padding(.trailing, line == 3 ? 120 : line == 1 ? 24 : 0)
                }
            }

            VStack(alignment: .leading, spacing: 12) {
                Capsule().frame(width: 150, height: 20)
                RoundedRectangle(cornerRadius: 20, style: .continuous)
                    .frame(height: 180)
            }
        }
        .foregroundStyle(AppPalette.ctaFill)
        .shimmering()
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Загрузка описания")
    }
}
