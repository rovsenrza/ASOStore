import SwiftUI

/// The app's few animation curves. Springs everywhere a finger is involved,
/// so motion follows the gesture instead of a clock.
enum Motion {
    /// Press feedback: quick, a little bounce.
    static let press = Animation.spring(response: 0.26, dampingFraction: 0.7)
    /// A control changing what it shows (install → progress → ready).
    static let state = Animation.spring(response: 0.42, dampingFraction: 0.86)
    /// Content arriving on screen.
    static let reveal = Animation.spring(response: 0.55, dampingFraction: 0.9)
    /// Filters and selection indicators sliding between options.
    static let select = Animation.spring(response: 0.34, dampingFraction: 0.82)
}

/// Cards, rows and chips sink slightly under the finger.
struct PressableStyle: ButtonStyle {
    var scale = 0.97

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? scale : 1)
            .opacity(configuration.isPressed ? 0.88 : 1)
            .animation(Motion.press, value: configuration.isPressed)
    }
}

extension View {
    /// Rows settle in as they scroll into view, and ease out as they leave.
    func scrollReveal() -> some View {
        scrollTransition(.animated(Motion.reveal).threshold(.visible(0.1))) { content, phase in
            content
                .opacity(phase.isIdentity ? 1 : 0)
                .offset(y: phase == .bottomTrailing ? 18 : 0)
                .scaleEffect(phase.isIdentity ? 1 : 0.97, anchor: .top)
        }
    }
}
