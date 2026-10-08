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
    ///
    /// Not `.interactive()`: that variant keeps a live touch-tracking shimmer/refraction
    /// recomputing on every frame, and a feed screen can show several of these capsules at
    /// once (the hero banner's install button plus one per row below it) — reported as the
    /// whole screen trembling/juddering while just sitting on the Home tab. `.regular` still
    /// looks like glass, just without that continuous recompute.
    @ViewBuilder
    func glassCapsule() -> some View {
        if #available(iOS 26, *) {
            glassEffect(.regular, in: .capsule)
        } else {
            background(.ultraThinMaterial, in: Capsule())
        }
    }

    /// Tinted Liquid Glass capsule for a control that carries colour (the ready-to-install
    /// action); a solid capsule before iOS 26. See `glassCapsule()` on why not `.interactive()`.
    @ViewBuilder
    func glassCapsule(tint: Color) -> some View {
        if #available(iOS 26, *) {
            glassEffect(.regular.tint(tint), in: .capsule)
        } else {
            background(tint, in: Capsule())
        }
    }

    /// Round Liquid Glass button face (toolbar-like actions over content). See `glassCapsule()`
    /// on why not `.interactive()`.
    @ViewBuilder
    func glassCircle() -> some View {
        if #available(iOS 26, *) {
            glassEffect(.regular, in: .circle)
        } else {
            background(.ultraThinMaterial, in: Circle())
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
