import SwiftUI

/// Layout numbers the app page's sections share.
enum AppDetailLayout {
    /// Text is comfortable to read up to about this width; wider windows (iPad, landscape) centre it.
    static let readingWidth = 700.0

    /// Side margin that keeps content inside the reading width. Sideways shelves use it as their
    /// content margin, so they still scroll out to the window's edges.
    static func sideInset(for width: CGFloat) -> CGFloat {
        max(AppSpacing.standard, (width - readingWidth) / 2)
    }
}

extension View {
    /// `zoomTransition(sourceID:in:)` that can be switched off (Reduce Motion): the screen then
    /// arrives with the system's plain transition.
    @ViewBuilder
    func zoomTransition<ID: Hashable>(sourceID: ID, in namespace: Namespace.ID, enabled: Bool) -> some View {
        if enabled {
            zoomTransition(sourceID: sourceID, in: namespace)
        } else {
            self
        }
    }

    /// A section title on the app page: a heading for VoiceOver's rotor, free to wrap at large text sizes.
    func detailSectionTitle() -> some View {
        font(.title2.bold())
            .fixedSize(horizontal: false, vertical: true)
            .accessibilityAddTraits(.isHeader)
    }
}

extension Animation {
    /// Content arriving on the app page: the usual spring, or a short fade under Reduce Motion.
    static func detailReveal(reduceMotion: Bool) -> Animation {
        reduceMotion ? .easeInOut(duration: 0.2) : Motion.reveal
    }
}
