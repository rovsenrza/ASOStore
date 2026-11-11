import SwiftUI

/// `shimmering()` only while something is on its way.
struct SkeletonSweep: ViewModifier {
    let isActive: Bool

    func body(content: Content) -> some View {
        if isActive {
            content.shimmering()
        } else {
            content
        }
    }
}
