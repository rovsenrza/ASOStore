import SwiftUI
import UIKit

/// Restores the edge-swipe-to-go-back gesture on a screen that hides the system navigation
/// bar for a custom header (`.toolbar(.hidden, for: .navigationBar)` /
/// `.toolbarVisibility(.hidden, for: .navigationBar)`). Hiding the bar disables
/// `interactivePopGestureRecognizer` as a side effect — its usual delegate comes from the bar,
/// which is now gone — so without this every screen built with a custom header loses the
/// standard one-finger-from-the-edge way back, leaving only the small custom back button.
private struct SwipeBackEnabler: UIViewControllerRepresentable {
    func makeUIViewController(context: Context) -> UIViewController {
        UIViewController()
    }

    func updateUIViewController(_ controller: UIViewController, context: Context) {
        // Not available until the controller is actually installed in a navigation stack.
        DispatchQueue.main.async {
            guard let gesture = controller.navigationController?.interactivePopGestureRecognizer else { return }
            gesture.isEnabled = true
            // The default delegate is the navigation bar, which is hidden and would otherwise
            // keep the gesture disabled; dropping it falls back to UIKit's own default
            // handling, which still correctly does nothing on a stack's root screen.
            gesture.delegate = nil
        }
    }
}

extension View {
    /// Pair with any navigation-bar-hiding modifier to keep swipe-back working.
    func swipeBackEnabled() -> some View {
        background(SwipeBackEnabler().frame(width: 0, height: 0))
    }
}
