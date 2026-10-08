import SwiftUI

/// App page. Opens with the summary the list already had and fills in the
/// full detail from the API (description, release notes, screenshots).
struct AppDetailView: View {
    @Environment(\.catalog) private var catalog
    @State private var app: StoreApp
    @State private var loadError: APIError?
    @State private var isStale = false
    @State private var expandsDescription = false
    /// The header's install button has scrolled away: the glass bar at the bottom takes over.
    @State private var headerHidden = false
    @State private var viewer: ScreenshotSelection?
    @Namespace private var screenshotZoom
    @Environment(\.dismiss) private var dismiss

    init(app: StoreApp) {
        _app = State(initialValue: app)
    }

    var body: some View {
        // A root GeometryReader reads the real top safe-area inset; the scroll content then
        // ignores it, so the banner bleeds up under the status bar/notch like the App Store.
        GeometryReader { proxy in
            let topInset = proxy.safeAreaInsets.top
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if app.featureImageURL != nil {
                        StretchyBanner(url: app.featureImageURL, topInset: topInset)
                    } else {
                        // No banner: keep the header clear of the notch AND of the floating
                        // back button overlaid at `topInset..topInset + 36` below — otherwise
                        // the app icon starts high enough to sit right under it.
                        Color.clear.frame(height: topInset + 44)
                    }
                    VStack(alignment: .leading, spacing: 28) {
                        if isStale {
                            StaleDataBanner()
                        }
                        appHeader
                        if let loadError {
                            errorNotice(loadError)
                        }
                        facts
                        screenshots
                        about
                        whatsNew
                        information
                    }
                    .padding(.horizontal, AppSpacing.standard)
                    .padding(.top, app.featureImageURL == nil ? 8 : -44)
                    .padding(.bottom, 40)
                }
            }
            .ignoresSafeArea(edges: .top)
            .scrollIndicators(.hidden)
            .background(AppPalette.canvas)
            .onScrollGeometryChange(for: Bool.self) { geometry in
                // Past the header's install button (the banner, when there is one, sits above it).
                geometry.contentOffset.y + geometry.contentInsets.top > 190 + (app.featureImageURL == nil ? 0 : StretchyBanner.height - 44)
            } action: { _, hidden in
                withAnimation(Motion.state) { headerHidden = hidden }
            }
            .safeAreaInset(edge: .bottom) {
                if headerHidden {
                    InstallBar(app: app)
                        .padding(.horizontal, AppSpacing.standard)
                        .padding(.bottom, 6)
                        .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            // Full-bleed banner: hide the system bar and float a glass back button over the artwork.
            .toolbar(.hidden, for: .navigationBar)
            .swipeBackEnabled()
            .overlay(alignment: .topLeading) {
                Button { dismiss() } label: {
                    Image(systemName: "chevron.backward")
                        .font(.system(size: 16, weight: .bold))
                        .foregroundStyle(.white)
                        .frame(width: 36, height: 36)
                        .glassCircle()
                }
                .padding(.top, topInset)
                .padding(.leading, 12)
                .accessibilityLabel("Назад")
            }
            .fullScreenCover(item: $viewer) { selection in
                ScreenshotViewer(urls: app.screenshots, selection: selection.index)
                    .navigationTransition(.zoom(sourceID: selection.index, in: screenshotZoom))
            }
            .task { await load() }
            .refreshable { await load() }
        }
    }

    private func load() async {
        do {
            let detail = try await catalog.app(id: app.id)
            withAnimation(Motion.reveal) {
                app = StoreApp(detail.value)
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

    private func errorNotice(_ error: APIError) -> some View {
        HStack {
            Label(error.code == .offline || error.code == .networkError ? "Нет подключения" : "Не удалось загрузить описание", systemImage: "exclamationmark.triangle")
                .font(.footnote)
                .foregroundStyle(.secondary)
            Spacer()
            Button("Повторить") {
                Task { await load() }
            }
            .font(.footnote.bold())
        }
    }

    // MARK: Header

    private var appHeader: some View {
        VStack(alignment: .leading, spacing: 18) {
            HStack(alignment: .bottom, spacing: 16) {
                AppIconView(app: app, size: 108)
                    .overlay(RoundedRectangle(cornerRadius: 108 * 0.22, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.35), lineWidth: 0.5))
                    .shadow(color: .black.opacity(0.18), radius: 14, y: 6)

                VStack(alignment: .leading, spacing: 4) {
                    Text(app.name)
                        .font(.title2.bold())
                        .lineLimit(3)
                    if !app.subtitle.isEmpty {
                        Text(app.subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(2)
                    }
                    Text(app.developer)
                        .font(.subheadline.weight(.medium))
                        .foregroundStyle(AppPalette.accent)
                        .lineLimit(1)
                }
                .padding(.bottom, 4)
            }

            AppActionButton(app: app, style: .prominent)
        }
    }

    // MARK: Facts

    /// Version, age, size, category and iOS as a row that scrolls sideways.
    private var facts: some View {
        ScrollView(.horizontal) {
            HStack(alignment: .top, spacing: 0) {
                fact(label: "Версия", value: app.version.isEmpty ? "—" : app.version, symbol: nil)
                factDivider
                fact(label: "Возраст", value: app.ageRating, symbol: nil)
                factDivider
                fact(label: "Размер", value: app.size, symbol: nil)
                factDivider
                fact(label: "Категория", value: nil, symbol: app.systemImage, caption: app.category)
                if let minIOS = app.minIOSVersion {
                    factDivider
                    fact(label: "Совместимость", value: "iOS \(minIOS)+", symbol: nil)
                }
            }
            .padding(.vertical, 14)
        }
        .scrollIndicators(.hidden)
        .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
        .padding(.horizontal, -AppSpacing.standard)
        .overlay(alignment: .top) { Divider() }
        .overlay(alignment: .bottom) { Divider() }
    }

    private var factDivider: some View {
        Divider().frame(height: 40).padding(.top, 6)
    }

    private func fact(label: String, value: String?, symbol: String?, caption: String? = nil) -> some View {
        VStack(spacing: 5) {
            Text(label.uppercased())
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.tertiary)
            if let symbol {
                Image(systemName: symbol)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(.secondary)
                    .frame(height: 24)
            } else {
                Text(value ?? "—")
                    .font(.title3.weight(.bold))
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                    .frame(height: 24)
            }
            if let caption {
                Text(caption)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .lineLimit(1)
            }
        }
        .frame(minWidth: 96)
        .padding(.horizontal, 6)
        .accessibilityElement(children: .combine)
    }

    // MARK: Screenshots

    @ViewBuilder
    private var screenshots: some View {
        if !app.screenshots.isEmpty || !app.screenshotTitles.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                Text("Предпросмотр")
                    .font(.title2.bold())

                ScrollView(.horizontal) {
                    LazyHStack(spacing: 12) {
                        if app.screenshots.isEmpty {
                            ForEach(Array(app.screenshotTitles.enumerated()), id: \.offset) { index, title in
                                ScreenshotMockView(app: app, title: title, index: index)
                            }
                        } else {
                            ForEach(Array(app.screenshots.enumerated()), id: \.offset) { index, url in
                                Button {
                                    viewer = ScreenshotSelection(index: index)
                                } label: {
                                    ScreenshotImage(url: url)
                                        .frame(width: 236, height: 460)
                                        .clipShape(.rect(cornerRadius: 26, style: .continuous))
                                        .overlay(RoundedRectangle(cornerRadius: 26, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.3), lineWidth: 0.5))
                                }
                                .buttonStyle(PressableStyle(scale: 0.97))
                                .matchedTransitionSource(id: index, in: screenshotZoom)
                                .scrollTransition(axis: .horizontal) { content, phase in
                                    content.scaleEffect(phase.isIdentity ? 1 : 0.94)
                                }
                                .accessibilityLabel("Снимок экрана \(index + 1) из \(app.screenshots.count)")
                            }
                        }
                    }
                    .scrollTargetLayout()
                }
                .scrollTargetBehavior(.viewAligned)
                .scrollIndicators(.hidden)
                .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
                .padding(.horizontal, -AppSpacing.standard)
            }
        }
    }

    // MARK: Text

    @ViewBuilder
    private var about: some View {
        if !app.description.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                Text("Об этом приложении")
                    .font(.title2.bold())
                Text(app.description)
                    .font(.body)
                    .lineSpacing(3)
                    .lineLimit(expandsDescription ? nil : 4)
                Button {
                    withAnimation(Motion.reveal) { expandsDescription.toggle() }
                } label: {
                    HStack(spacing: 4) {
                        Text(expandsDescription ? "Свернуть" : "Ещё")
                        Image(systemName: "chevron.down")
                            .font(.caption.weight(.bold))
                            .rotationEffect(.degrees(expandsDescription ? 180 : 0))
                    }
                    .font(.body.weight(.medium))
                }
            }
        }
    }

    @ViewBuilder
    private var whatsNew: some View {
        if !app.whatsNew.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .firstTextBaseline) {
                    Text("Что нового")
                        .font(.title2.bold())
                    Spacer()
                    if !app.version.isEmpty {
                        Text("Версия \(app.version)")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                Text(app.whatsNew)
                    .font(.body)
                    .lineSpacing(3)
            }
        }
    }

    private var information: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Информация")
                .font(.title2.bold())
            VStack(spacing: 0) {
                informationRow(title: "Продавец", value: app.developer)
                informationRow(title: "Категория", value: app.category)
                informationRow(title: "Размер", value: app.size)
                informationRow(title: "Совместимость", value: app.minIOSVersion.map { "iOS \($0) и новее" } ?? "iPhone")
                informationRow(title: "Возраст", value: app.ageRating, isLast: app.supportURL == nil && app.privacyURL == nil)
                if let supportURL = app.supportURL {
                    linkRow(title: "Поддержка", url: supportURL, isLast: app.privacyURL == nil)
                }
                if let privacyURL = app.privacyURL {
                    linkRow(title: "Конфиденциальность", url: privacyURL, isLast: true)
                }
            }
            .padding(.horizontal, 16)
            .background(AppPalette.card, in: .rect(cornerRadius: 20, style: .continuous))
        }
    }

    private func informationRow(title: String, value: String, isLast: Bool = false) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title).foregroundStyle(.secondary)
            Spacer(minLength: 16)
            Text(value).multilineTextAlignment(.trailing)
        }
        .font(.subheadline)
        .padding(.vertical, 13)
        .overlay(alignment: .bottom) { if !isLast { Divider() } }
        .accessibilityElement(children: .combine)
    }

    private func linkRow(title: String, url: URL, isLast: Bool) -> some View {
        Link(destination: url) {
            HStack {
                Text(title).foregroundStyle(.primary)
                Spacer()
                Image(systemName: "arrow.up.right")
                    .font(.caption.bold())
                    .foregroundStyle(AppPalette.accent)
            }
            .font(.subheadline)
            .padding(.vertical, 13)
            .overlay(alignment: .bottom) { if !isLast { Divider() } }
        }
    }
}

// MARK: - Pieces

/// The banner above the header. Pulling down stretches it instead of showing a gap.
private struct StretchyBanner: View {
    let url: URL?
    /// The top safe-area inset the artwork extends up through, so it reaches the notch.
    var topInset: CGFloat = 0
    static let height = 196.0

    var body: some View {
        GeometryReader { proxy in
            let pull = max(0, proxy.frame(in: .scrollView).minY)
            ScreenshotImage(url: url)
                .frame(width: proxy.size.width, height: Self.height + topInset + pull)
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

/// A remote screenshot or banner that fades in over a quiet placeholder.
private struct ScreenshotImage: View {
    let url: URL?

    var body: some View {
        CachedImage(url: url, maxPixel: ImagePixels.screenshot) { image in
            image.resizable().scaledToFill()
        } placeholder: {
            AppPalette.elevated.shimmering()
        }
    }
}

/// Pinned to the bottom once the header scrolls away: the app and its install action on glass.
private struct InstallBar: View {
    let app: StoreApp

    var body: some View {
        HStack(spacing: 12) {
            AppIconView(app: app, size: 40)
            VStack(alignment: .leading, spacing: 1) {
                Text(app.name)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(1)
                Text(app.developer)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            AppActionButton(app: app)
        }
        .padding(.leading, 10)
        .padding(.trailing, 8)
        .padding(.vertical, 8)
        .glassCard(cornerRadius: 26)
    }
}

private struct ScreenshotSelection: Identifiable {
    let index: Int
    var id: Int { index }
}

/// Screenshots full screen, swiping between them; zooms out of the one tapped.
private struct ScreenshotViewer: View {
    let urls: [URL]
    @State var selection: Int
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        ZStack(alignment: .topTrailing) {
            Color.black.ignoresSafeArea()
            TabView(selection: $selection) {
                ForEach(Array(urls.enumerated()), id: \.offset) { index, url in
                    CachedImage(url: url, maxPixel: ImagePixels.screenshot) { image in
                        image.resizable().scaledToFit()
                    } placeholder: {
                        ProgressView().tint(.white)
                    }
                    .padding(.horizontal, 12)
                    .tag(index)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: urls.count > 1 ? .always : .never))

            Button {
                dismiss()
            } label: {
                Image(systemName: "xmark")
                    .font(.system(size: 15, weight: .bold))
                    .foregroundStyle(.white)
                    .frame(width: 44, height: 44)
                    .glassCircle()
            }
            .padding(16)
            .accessibilityLabel("Закрыть")
        }
        .statusBarHidden()
    }
}

#if DEBUG
#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    NavigationStack {
        AppDetailView(app: MockCatalog.featured)
    }
    .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
}
#endif
