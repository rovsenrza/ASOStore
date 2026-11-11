import SwiftUI

/// App page. Opens at once with the summary the list already had and fills in the full
/// detail from the API (description, release notes, screenshots, related apps); the long
/// part shows a skeleton until then. Opened from a link it knows only the ID, so the header
/// is a skeleton too.
struct AppDetailView: View {
    @Environment(\.catalog) private var catalog
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var app: StoreApp
    /// The detail response has arrived (fresh or from the offline cache).
    @State private var hasDetail = false
    @State private var loadError: APIError?
    @State private var isStale = false
    /// The header's install button has scrolled away: the glass bar at the bottom takes over.
    @State private var headerHidden = false
    /// Where the header ends in the scrolling content; the install bar appears past it.
    @State private var headerBottom: CGFloat = 320
    @State private var viewer: ScreenshotSelection?
    @Namespace private var screenshotZoom
    @ScaledMetric(relativeTo: .body) private var scaledBackSize = 36.0

    init(app: StoreApp) {
        _app = State(initialValue: app)
    }

    private var backSize: CGFloat { min(max(scaledBackSize, 36), 56) }

    var body: some View {
        // A root GeometryReader reads the real top safe-area inset; the scroll content then
        // ignores it, so the banner bleeds up under the status bar/notch like the App Store.
        GeometryReader { proxy in
            let topInset = proxy.safeAreaInsets.top
            // A phone on its side has no top inset: keep the back button off the edge anyway.
            let backTop = max(topInset, 8)
            let inset = AppDetailLayout.sideInset(for: proxy.size.width)
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if app.featureImageURL != nil {
                        StretchyBanner(url: app.featureImageURL, topInset: topInset)
                    } else {
                        // No banner: keep the header clear of the notch AND of the floating
                        // back button overlaid below — otherwise the app icon starts high
                        // enough to sit right under it.
                        Color.clear.frame(height: backTop + backSize + 8)
                    }
                    sections(inset: inset)
                        .padding(.top, app.featureImageURL == nil ? 8 : -44)
                        .padding(.bottom, 40)
                }
                .coordinateSpace(name: "detailContent")
                .reportsScrollOffset()
            }
            .coordinateSpace(name: "detailScroll")
            .ignoresSafeArea(edges: .top)
            .scrollIndicators(.hidden)
            .background(AppPalette.canvas)
            // Past the header's install button, about as it slips under the status bar.
            .onScrolledPast(max(60, headerBottom - topInset - 8)) { hidden in
                withAnimation(.detailReveal(reduceMotion: reduceMotion)) { headerHidden = hidden }
            }
            .safeAreaInset(edge: .bottom) {
                if headerHidden, !app.isPlaceholder {
                    InstallBar(app: app)
                        .padding(.horizontal, AppSpacing.standard)
                        .padding(.bottom, 6)
                        .transition(reduceMotion ? .opacity : .move(edge: .bottom).combined(with: .opacity))
                }
            }
            // Full-bleed banner: hide the system bar and float a glass back button over the artwork.
            .toolbar(.hidden, for: .navigationBar)
            .swipeBackEnabled()
            .overlay(alignment: .topLeading) {
                // Whether this overlay starts at the screen's top or under the status bar depends
                // on the modifiers above it, so the button is placed from where the overlay really is.
                GeometryReader { overlay in
                    backButton
                        .padding(.top, max(0, backTop - overlay.frame(in: .global).minY))
                        .padding(.leading, 8)
                }
            }
            .fullScreenCover(item: $viewer) { selection in
                ScreenshotViewer(urls: app.screenshots, selection: selection.index)
                    .zoomTransition(sourceID: selection.index, in: screenshotZoom, enabled: !reduceMotion)
            }
            .task { await load() }
            .refreshable { await load() }
        }
    }

    // MARK: Sections

    private func sections(inset: CGFloat) -> some View {
        VStack(alignment: .leading, spacing: 28) {
            Group {
                if isStale {
                    StaleDataBanner()
                }
                header
                if let loadError {
                    AppDetailErrorNotice(error: loadError, appIsUnknown: app.isPlaceholder) {
                        Task { await retry() }
                    }
                }
            }
            .padding(.horizontal, inset)

            if !app.isPlaceholder {
                AppDetailFacts(app: app, inset: inset)
            }

            if hasDetail {
                AppDetailScreenshots(app: app, inset: inset, zoomNamespace: screenshotZoom, viewer: $viewer)
                Group {
                    AppDetailAbout(description: app.description)
                    AppDetailWhatsNew(app: app)
                    AppDetailInfo(app: app)
                }
                .padding(.horizontal, inset)
                AppShelf(title: "Похожие приложения", apps: app.related, source: "detail-related", inset: inset)
                if app.hasKnownDeveloper {
                    AppShelf(title: "Ещё от издателя", apps: app.moreFromPublisher, source: "detail-publisher", inset: inset)
                }
            } else if loadError == nil {
                AppDetailSkeleton()
                    .padding(.horizontal, inset)
                    .transition(.opacity)
            } else if !app.isPlaceholder {
                // The request failed and nothing is cached: show what the list already knew.
                AppDetailInfo(app: app)
                    .padding(.horizontal, inset)
            }
        }
    }

    @ViewBuilder
    private var header: some View {
        Group {
            if app.isPlaceholder {
                AppDetailHeaderSkeleton(isLoading: loadError == nil)
            } else {
                AppDetailHeader(app: app)
            }
        }
        .onGeometryChange(for: CGFloat.self) { proxy in
            // Measured in the scrolling content, so the value does not change as the page scrolls.
            proxy.frame(in: .named("detailContent")).maxY
        } action: { bottom in
            headerBottom = bottom
        }
    }

    private var backButton: some View {
        Button {
            dismiss()
        } label: {
            Label("Назад", systemImage: "chevron.backward")
                .labelStyle(.iconOnly)
                .font(.callout.weight(.bold))
                // Dark on light glass, light on dark: readable over the banner and, once it has
                // scrolled away (or when there is none), over the canvas.
                .foregroundStyle(Color.primary)
                .frame(width: backSize, height: backSize)
                .glassCircle()
                // The glass circle stays small; the touch target is a full 44 pt.
                .frame(minWidth: 44, minHeight: 44)
                .contentShape(.rect)
        }
        .accessibilityIdentifier("detail-back")
    }

    // MARK: Loading

    private func load() async {
        do {
            let detail = try await catalog.app(id: app.id)
            withAnimation(.detailReveal(reduceMotion: reduceMotion)) {
                app = StoreApp(detail.value)
                hasDetail = true
                isStale = detail.isStale
                loadError = nil
            }
        } catch is CancellationError {
            return
        } catch let error as APIError {
            loadError = error
        } catch {
            loadError = APIError(code: .internal)
        }
    }

    /// «Повторить»: the notice gives way to the skeleton while the request runs again.
    private func retry() async {
        loadError = nil
        await load()
    }
}

#if DEBUG
#Preview {
    NavigationStack {
        AppDetailView(app: MockCatalog.featured)
    }
    .previewEnvironment()
}

#Preview("По ссылке") {
    NavigationStack {
        AppDetailView(app: .loading(id: "focus-notes"))
    }
    .previewEnvironment()
}
#endif
