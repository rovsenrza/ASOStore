import SwiftUI

/// The banner above the header. Pulling down stretches it instead of showing a gap;
/// with Reduce Motion it stays pinned at its size.
struct StretchyBanner: View {
    let url: URL?
    /// The top safe-area inset the artwork extends up through, so it reaches the notch.
    var topInset: CGFloat = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    static let height = 196.0

    var body: some View {
        GeometryReader { proxy in
            let pull = max(0, proxy.frame(in: .detailScroll).minY)
            ScreenshotImage(url: url)
                .frame(width: proxy.size.width, height: Self.height + topInset + (reduceMotion ? 0 : pull))
                .clipped()
                .overlay {
                    // The header below sits on the canvas: fade the artwork into it.
                    LinearGradient(stops: [.init(color: .clear, location: 0.45), .init(color: AppPalette.canvas, location: 1)], startPoint: .top, endPoint: .bottom)
                }
                .offset(y: -pull)
        }
        .frame(height: Self.height + topInset)
        .accessibilityHidden(true)
    }
}
