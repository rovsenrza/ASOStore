import ObjectiveC
import SwiftUI
import UIKit

/// Restores the edge-swipe-to-go-back gesture on a screen that hides the system navigation
/// bar for a custom header (`.toolbar(.hidden, for: .navigationBar)` /
/// `.navigationBarHiddenCompat()`). Hiding the bar disables
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
            guard let navigationController = controller.navigationController else { return }
            SwipeBackDelegate.install(on: navigationController)
        }
    }
}

/// Lets the pop gesture begin only when there is a screen to go back to.
///
/// A gesture with no delegate at all also begins on the stack's root screen, where there is
/// nothing to pop: UIKit then starts a transition that never ends and the stack stops
/// responding to touches. Asking the stack first avoids that.
final class SwipeBackDelegate: NSObject, UIGestureRecognizerDelegate {
    private static var key: UInt8 = 0

    private weak var navigationController: UINavigationController?

    init(navigationController: UINavigationController) {
        self.navigationController = navigationController
    }

    /// One delegate per navigation controller, kept alive by it (the gesture's `delegate` is weak).
    static func install(on navigationController: UINavigationController) {
        guard let gesture = navigationController.interactivePopGestureRecognizer else { return }
        gesture.isEnabled = true
        if let installed = objc_getAssociatedObject(navigationController, &key) as? SwipeBackDelegate {
            if gesture.delegate !== installed { gesture.delegate = installed }
            return
        }
        let delegate = SwipeBackDelegate(navigationController: navigationController)
        objc_setAssociatedObject(navigationController, &key, delegate, .OBJC_ASSOCIATION_RETAIN_NONATOMIC)
        gesture.delegate = delegate
    }

    func gestureRecognizerShouldBegin(_ gestureRecognizer: UIGestureRecognizer) -> Bool {
        guard let navigationController else { return false }
        // Not on the root screen, and not while a push or pop is still animating.
        return navigationController.viewControllers.count > 1 && navigationController.transitionCoordinator == nil
    }
}

extension View {
    /// Pair with any navigation-bar-hiding modifier to keep swipe-back working.
    func swipeBackEnabled() -> some View {
        background(SwipeBackEnabler().frame(width: 0, height: 0))
    }
}
