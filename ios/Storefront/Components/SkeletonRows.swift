import SwiftUI

/// Placeholder rows shaped like the rows that will replace them, with a light sweeping
/// across: the list is on its way, and its layout does not jump when it arrives.
struct SkeletonRows: View {
    var count = 6

    var body: some View {
        VStack(spacing: 0) {
            ForEach(0..<count, id: \.self) { index in
                HStack(spacing: 14) {
                    RoundedRectangle(cornerRadius: 62 * 0.22, style: .continuous)
                        .frame(width: 62, height: 62)
                    VStack(alignment: .leading, spacing: 8) {
                        Capsule().frame(width: index.isMultiple(of: 2) ? 150 : 120, height: 13)
                        Capsule().frame(width: index.isMultiple(of: 3) ? 90 : 110, height: 11)
                    }
                    Spacer()
                    Capsule().frame(width: 88, height: 34)
                }
                .frame(minHeight: 78)
                .foregroundStyle(AppPalette.ctaFill)
                if index < count - 1 {
                    Divider().padding(.leading, 76)
                }
            }
        }
        .shimmering()
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Загрузка")
    }
}

/// Главная on its way: a hero card and a shelf.
struct FeedSkeleton: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 30) {
            RoundedRectangle(cornerRadius: 28, style: .continuous)
                .fill(AppPalette.ctaFill)
                .frame(height: 320)
                .shimmering()
                .padding(.horizontal, AppSpacing.standard)
            VStack(alignment: .leading, spacing: 12) {
                Capsule().fill(AppPalette.ctaFill).frame(width: 180, height: 20).shimmering()
                SkeletonRows(count: 3)
            }
            .padding(.horizontal, AppSpacing.standard)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Загрузка")
    }
}

extension View {
    /// A highlight sweeping across the view's shapes (skeletons while content loads).
    func shimmering() -> some View {
        modifier(Shimmer())
    }
}

private struct Shimmer: ViewModifier {
    @State private var phase: CGFloat = -1
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    func body(content: Content) -> some View {
        content
            .overlay {
                GeometryReader { proxy in
                    LinearGradient(colors: [.clear, .white.opacity(0.5), .clear], startPoint: .leading, endPoint: .trailing)
                        .frame(width: proxy.size.width * 0.5)
                        .offset(x: phase * proxy.size.width * 1.5)
                        .blendMode(.plusLighter)
                }
                .mask(content)
                .opacity(reduceMotion ? 0 : 1)
                .allowsHitTesting(false)
            }
            .onAppear {
                withAnimation(.linear(duration: 1.3).repeatForever(autoreverses: false)) { phase = 1 }
            }
    }
}
