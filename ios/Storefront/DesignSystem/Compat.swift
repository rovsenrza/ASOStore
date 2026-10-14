import SwiftUI

/// iOS 16 support for the iOS 17/18 modifiers the app uses. Newer systems get the real thing;
/// iOS 16 gets the nearest older equivalent, or nothing where the modifier is only polish.
extension View {
    /// `onChange(of:initial:)`, which iOS 16 lacks: `action` receives the new value.
    @ViewBuilder
    func onChangeCompat<Value: Equatable>(of value: Value, initial: Bool = false, _ action: @escaping (Value) -> Void) -> some View {
        if #available(iOS 17, *) {
            onChange(of: value, initial: initial) { _, newValue in action(newValue) }
        } else {
            onChange(of: value, perform: action)
                .onAppear { if initial { action(value) } }
        }
    }

    /// Haptic on change; silent on iOS 16.
    @ViewBuilder
    func selectionFeedback<Trigger: Equatable>(trigger: Trigger) -> some View {
        if #available(iOS 17, *) {
            sensoryFeedback(.selection, trigger: trigger)
        } else {
            self
        }
    }

    /// A changing number rolls its digits (iOS 17); cross-fades on iOS 16.
    @ViewBuilder
    func numericTransition(_ value: Double) -> some View {
        if #available(iOS 17, *) {
            contentTransition(.numericText(value: value))
        } else {
            contentTransition(.numericText())
        }
    }

    /// Hides the navigation bar (`toolbarVisibility` is iOS 18).
    @ViewBuilder
    func navigationBarHiddenCompat() -> some View {
        if #available(iOS 18, *) {
            toolbarVisibility(.hidden, for: .navigationBar)
        } else {
            toolbar(.hidden, for: .navigationBar)
        }
    }

    /// Horizontal inset of a scroll view's content. Before iOS 17 a safe-area inset does the same:
    /// the content starts inset but still scrolls out to the screen edge.
    @ViewBuilder
    func horizontalContentMargins(_ length: CGFloat) -> some View {
        if #available(iOS 17, *) {
            contentMargins(.horizontal, length, for: .scrollContent)
        } else {
            safeAreaInset(edge: .leading, spacing: 0) { Color.clear.frame(width: length) }
                .safeAreaInset(edge: .trailing, spacing: 0) { Color.clear.frame(width: length) }
        }
    }

    /// Width relative to the enclosing horizontal scroll view (inside its content margins).
    /// Before iOS 17 the screen width stands in for the container, which is the same on iPhone.
    @ViewBuilder
    func horizontalContainerWidth(margin: CGFloat, _ width: @escaping (CGFloat) -> CGFloat = { $0 }) -> some View {
        if #available(iOS 17, *) {
            containerRelativeFrame(.horizontal) { length, _ in width(length) }
        } else {
            frame(width: width(UIScreen.main.bounds.width - 2 * margin))
        }
    }

    /// Items off centre shrink back while a shelf scrolls; static before iOS 17.
    @ViewBuilder
    func scrollShrink(_ scale: CGFloat, opacity: Double = 1) -> some View {
        if #available(iOS 17, *) {
            scrollTransition(axis: .horizontal) { content, phase in
                content
                    .scaleEffect(phase.isIdentity ? 1 : scale)
                    .opacity(phase.isIdentity ? 1 : opacity)
            }
        } else {
            self
        }
    }

    /// Zoom from a source element (iOS 18); a plain transition earlier.
    @ViewBuilder
    func zoomTransitionSource<ID: Hashable>(id: ID, in namespace: Namespace.ID) -> some View {
        if #available(iOS 18, *) {
            matchedTransitionSource(id: id, in: namespace)
        } else {
            self
        }
    }

    @ViewBuilder
    func zoomTransition<ID: Hashable>(sourceID: ID, in namespace: Namespace.ID) -> some View {
        if #available(iOS 18, *) {
            navigationTransition(.zoom(sourceID: sourceID, in: namespace))
        } else {
            self
        }
    }

    /// Paging a horizontal shelf by item; free scrolling before iOS 17.
    @ViewBuilder
    func viewAlignedScrolling() -> some View {
        if #available(iOS 17, *) {
            scrollTargetBehavior(.viewAligned)
        } else {
            self
        }
    }

    /// Marks the layout whose children `viewAlignedScrolling` snaps to.
    @ViewBuilder
    func scrollTargetLayoutCompat() -> some View {
        if #available(iOS 17, *) {
            scrollTargetLayout()
        } else {
            self
        }
    }
}

/// `ContentUnavailableView` from iOS 17, drawn the same way on iOS 16.
struct UnavailableView<Actions: View>: View {
    let title: String
    let systemImage: String
    var description: String?
    @ViewBuilder var actions: () -> Actions

    var body: some View {
        if #available(iOS 17, *) {
            ContentUnavailableView {
                Label(title, systemImage: systemImage)
            } description: {
                if let description { Text(description) }
            } actions: {
                actions()
            }
        } else {
            VStack(spacing: 12) {
                Image(systemName: systemImage)
                    .font(.system(size: 44))
                    .foregroundStyle(.secondary)
                Text(title)
                    .font(.title2.bold())
                    .multilineTextAlignment(.center)
                if let description {
                    Text(description)
                        .font(.callout)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                actions()
            }
            .padding(.horizontal, 32)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }
}

extension UnavailableView where Actions == EmptyView {
    init(_ title: String, systemImage: String, description: String? = nil) {
        self.init(title: title, systemImage: systemImage, description: description) { EmptyView() }
    }
}

/// `ContentUnavailableView.search(text:)` with the same wording on iOS 16.
struct SearchUnavailableView: View {
    let text: String

    var body: some View {
        if #available(iOS 17, *) {
            ContentUnavailableView.search(text: text)
        } else {
            UnavailableView("Нет результатов по запросу «\(text)»", systemImage: "magnifyingglass", description: "Проверьте правописание или попробуйте новый запрос.")
        }
    }
}

extension CoordinateSpace {
    /// The app page's scroll view (`.scrollView` is iOS 17).
    static let detailScroll = CoordinateSpace.named("detailScroll")
}

private struct ScrollOffsetKey: PreferenceKey {
    static let defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = nextValue() }
}

extension View {
    /// On the scroll view's content: reports how far it has scrolled, for `onScrolledPast`
    /// before iOS 18. The scroll view needs `.coordinateSpace(name: "detailScroll")`.
    @ViewBuilder
    func reportsScrollOffset() -> some View {
        if #available(iOS 18, *) {
            self
        } else {
            background {
                GeometryReader { proxy in
                    Color.clear.preference(key: ScrollOffsetKey.self, value: -proxy.frame(in: .detailScroll).minY)
                }
            }
        }
    }

    /// On a scroll view: `action(true)` once scrolled further than `threshold`, `false` back above it.
    @ViewBuilder
    func onScrolledPast(_ threshold: CGFloat, _ action: @escaping (Bool) -> Void) -> some View {
        if #available(iOS 18, *) {
            onScrollGeometryChange(for: Bool.self) { geometry in
                geometry.contentOffset.y + geometry.contentInsets.top > threshold
            } action: { _, past in
                action(past)
            }
        } else {
            onPreferenceChange(ScrollOffsetKey.self) { offset in
                action(offset > threshold)
            }
        }
    }
}
