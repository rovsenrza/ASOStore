import SwiftUI

/// One page of the screenshot viewer: pinch or double-tap to zoom, drag to look around while
/// zoomed. At rest it takes no drag, so swiping between pages keeps working.
struct ZoomableScreenshot: View {
    let url: URL
    let label: String
    /// False while another page is showing: the zoom resets, so coming back starts fresh.
    let isCurrent: Bool
    /// Tells the viewer to stop paging while this page is zoomed.
    let onZoomChange: (Bool) -> Void

    @State private var scale: CGFloat = 1
    @State private var offset: CGSize = .zero
    @GestureState private var pinch: CGFloat = 1
    @GestureState private var drag: CGSize = .zero
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private static let maxScale: CGFloat = 4
    private static let doubleTapScale: CGFloat = 2.5

    private var isZoomed: Bool { scale > 1 }

    var body: some View {
        GeometryReader { proxy in
            let shownScale = Self.clamp(scale * pinch)
            let shownOffset = Self.clamp(CGSize(width: offset.width + drag.width, height: offset.height + drag.height), scale: shownScale, in: proxy.size)

            CachedImage(url: url, maxPixel: ImagePixels.screenshot) { image in
                image.resizable().scaledToFit()
            } placeholder: {
                ProgressView().tint(.white)
            }
            .padding(.horizontal, 12)
            .frame(width: proxy.size.width, height: proxy.size.height)
            .scaleEffect(shownScale)
            .offset(shownOffset)
            .contentShape(.rect)
            // A double tap needs the tap count, which a Button cannot give.
            .onTapGesture(count: 2) { toggleZoom(in: proxy.size) }
            .gesture(magnification(in: proxy.size))
            .simultaneousGesture(isZoomed ? pan(in: proxy.size) : nil)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(label)
            .accessibilityAddTraits(.isImage)
            .accessibilityAction(named: Text(isZoomed ? "Уменьшить" : "Увеличить")) { toggleZoom(in: proxy.size) }
        }
        .onChangeCompat(of: isCurrent) { isCurrent in
            if !isCurrent { reset(animated: false) }
        }
        .onChangeCompat(of: isZoomed) { onZoomChange($0) }
        .onDisappear { onZoomChange(false) }
    }

    // MARK: Gestures

    private func magnification(in size: CGSize) -> some Gesture {
        MagnificationGesture()
            .updating($pinch) { value, state, _ in state = value }
            .onEnded { value in
                let settled = Self.clamp(scale * value)
                animate {
                    // Close enough to natural size: snap back, so paging is not left locked.
                    if settled < 1.05 {
                        scale = 1
                        offset = .zero
                    } else {
                        scale = settled
                        offset = Self.clamp(offset, scale: settled, in: size)
                    }
                }
            }
    }

    private func pan(in size: CGSize) -> some Gesture {
        DragGesture()
            .updating($drag) { value, state, _ in state = value.translation }
            .onEnded { value in
                offset = Self.clamp(CGSize(width: offset.width + value.translation.width, height: offset.height + value.translation.height), scale: scale, in: size)
            }
    }

    private func toggleZoom(in size: CGSize) {
        if isZoomed {
            reset(animated: true)
        } else {
            animate { scale = Self.doubleTapScale }
        }
    }

    private func reset(animated: Bool) {
        guard scale != 1 || offset != .zero else { return }
        if animated {
            animate {
                scale = 1
                offset = .zero
            }
        } else {
            scale = 1
            offset = .zero
        }
    }

    private func animate(_ change: () -> Void) {
        withAnimation(reduceMotion ? nil : Motion.state, change)
    }

    // MARK: Limits

    static func clamp(_ scale: CGFloat) -> CGFloat {
        min(max(scale, 1), maxScale)
    }

    /// Keeps the zoomed picture from being dragged off the screen: at most half of what the
    /// zoom added can move out on each side.
    static func clamp(_ offset: CGSize, scale: CGFloat, in size: CGSize) -> CGSize {
        let limitX = max(0, size.width * (scale - 1) / 2)
        let limitY = max(0, size.height * (scale - 1) / 2)
        return CGSize(width: min(max(offset.width, -limitX), limitX), height: min(max(offset.height, -limitY), limitY))
    }
}
