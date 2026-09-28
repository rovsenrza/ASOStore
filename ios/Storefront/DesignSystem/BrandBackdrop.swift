import SwiftUI

/// The blue glow at the top of every tab, fading into the system background.
/// Strong in dark mode, a light tint in light mode.
struct BrandBackdrop: View {
    var body: some View {
        LinearGradient(
            stops: [
                .init(color: AppPalette.headerGlow, location: 0),
                .init(color: AppPalette.headerGlow.opacity(0.55), location: 0.35),
                .init(color: AppPalette.canvas, location: 1),
            ],
            startPoint: .top,
            endPoint: .bottom
        )
        .frame(height: 320)
        .frame(maxHeight: .infinity, alignment: .top)
        .ignoresSafeArea()
        .accessibilityHidden(true)
    }
}

extension View {
    /// Liquid Glass on iOS 26 and later, a material capsule before that.
    @ViewBuilder
    func glassCapsule() -> some View {
        if #available(iOS 26, *) {
            glassEffect(.regular.interactive(), in: .capsule)
        } else {
            background(.ultraThinMaterial, in: Capsule())
        }
    }

    /// Liquid Glass card on iOS 26 and later, with a material fallback on
    /// earlier supported releases.
    @ViewBuilder
    func glassCard(cornerRadius: CGFloat = 28) -> some View {
        if #available(iOS 26, *) {
            glassEffect(.regular, in: .rect(cornerRadius: cornerRadius))
        } else {
            background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: cornerRadius))
                .overlay {
                    RoundedRectangle(cornerRadius: cornerRadius)
                        .stroke(.white.opacity(0.12), lineWidth: 1)
                }
        }
    }
}
