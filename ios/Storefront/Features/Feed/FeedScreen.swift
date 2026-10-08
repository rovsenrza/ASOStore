import SwiftUI

@Observable
final class FeedModel {
    private(set) var state: LoadState<FeedDTO> = .loading
    private(set) var isStale = false

    func load(_ catalog: CatalogRepository, kind: CatalogKind) async {
        do {
            let feed = try await catalog.feed(kind)
            isStale = feed.isStale
            state = feed.value.sections.isEmpty ? .empty : .loaded(feed.value)
        } catch is CancellationError {
            return
        } catch {
            state = LoadState(error: error)
        }
    }

    /// See `CatalogListModel.resumeIfStuck`: the same cancel-without-recovery gap applies here.
    func resumeIfStuck(_ catalog: CatalogRepository, kind: CatalogKind) {
        guard case .loading = state else { return }
        Task { await load(catalog, kind: kind) }
    }
}

/// Главная: editor's picks and shelves first, then every app in one list («Все приложения»),
/// so there is always a way to browse the whole catalog without searching.
struct FeedScreen: View {
    var kind: CatalogKind = .all
    @Environment(\.catalog) private var catalog
    @Environment(SessionStore.self) private var session
    @Environment(AppRouter.self) private var router
    @State private var model = FeedModel()
    @State private var allApps = CatalogListModel()
    @Namespace private var zoom
    /// Gates `resumeIfStuck` to re-appearances only: the first appearance is `.task`'s job,
    /// and firing it there too would just race the initial load.
    @State private var appearedOnce = false

    var body: some View {
        NavigationStack {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 34) {
                    StoreHeader()
                    StateContainerView(state: model.state, retry: reload, skeleton: .feed) { feed in
                        LazyVStack(alignment: .leading, spacing: 34) {
                            if model.isStale {
                                StaleDataBanner().padding(.horizontal, AppSpacing.standard)
                            }
                            ForEach(feed.sections) { section in
                                FeedSectionView(section: section)
                            }
                            allAppsSection
                        }
                    }
                    .frame(minHeight: 420, alignment: .top)
                }
                .padding(.bottom, AppSpacing.generous)
            }
            .background { BrandBackdrop() }
            .background(AppPalette.canvas)
            .refreshable { await reloadAll() }
            .toolbarVisibility(.hidden, for: .navigationBar)
            .swipeBackEnabled()
            .environment(\.zoomNamespace, zoom)
            .storeDestinations()
            // Install buttons depend on who is signed in: reload when that changes.
            .task(id: session.user?.id) { await model.load(catalog, kind: kind) }
            .task(id: CatalogReloadKey(filter: allApps.filter, user: session.user?.id)) { await allApps.reload(catalog) }
            .onAppear {
                if appearedOnce {
                    model.resumeIfStuck(catalog, kind: kind)
                    allApps.resumeIfStuck(catalog)
                }
                appearedOnce = true
            }
        }
    }

    private var allAppsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .center, spacing: 10) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("Все приложения")
                        .font(.title2.weight(.bold))
                    if let total = allApps.total {
                        Text(CatalogView.count(total))
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .contentTransition(.numericText(value: Double(total)))
                    }
                }
                Spacer()
                SortMenu(sort: $allApps.filter.sort)
                Button {
                    router.selectedTab = .catalog
                } label: {
                    Text("Фильтры")
                        .font(.subheadline.weight(.semibold))
                        .padding(.horizontal, 14)
                        .frame(height: 44)
                        .glassCapsule()
                }
                .buttonStyle(PressableStyle())
                .accessibilityHint("Открыть каталог с категориями и фильтрами")
            }
            .padding(.horizontal, AppSpacing.standard)

            CatalogAppList(model: allApps, source: "home-all") {
                Task { await allApps.reload(catalog) }
            }
            .padding(.horizontal, AppSpacing.standard)
        }
    }

    private func reload() {
        Task { await reloadAll() }
    }

    private func reloadAll() async {
        async let feed: Void = model.load(catalog, kind: kind)
        async let list: Void = allApps.reload(catalog)
        _ = await (feed, list)
    }
}

/// One server-defined feed section. Unknown kinds are skipped (docs/api/openapi.yaml).
struct FeedSectionView: View {
    let section: FeedSectionDTO

    var body: some View {
        switch section.kind {
        case "featured":
            if !apps.isEmpty {
                HeroCarousel(apps: apps)
            }
        case "carousel":
            if !apps.isEmpty {
                AppRowsSection(title: section.title, apps: apps)
            }
        case "categories":
            let categories = (section.categories ?? []).map(StoreCategory.init)
            if !categories.isEmpty {
                CategoryShelf(title: section.title, categories: categories)
            }
        default:
            EmptyView()
        }
    }

    private var apps: [StoreApp] {
        (section.apps ?? []).map(StoreApp.init)
    }
}

/// Categories as tiles in two rows, paging sideways: symbol, name and how many apps.
struct CategoryShelf: View {
    let title: String
    let categories: [StoreCategory]

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(title)
                .font(.title2.weight(.bold))
                .padding(.horizontal, AppSpacing.standard)
            ScrollView(.horizontal) {
                LazyHGrid(rows: [GridItem(.fixed(64), spacing: 10), GridItem(.fixed(64), spacing: 10)], spacing: 10) {
                    ForEach(categories) { category in
                        NavigationLink(value: category) {
                            CategoryTile(category: category)
                        }
                        .buttonStyle(PressableStyle(scale: 0.96))
                        .accessibilityIdentifier("category-\(category.id)")
                    }
                }
                .scrollTargetLayout()
            }
            .scrollTargetBehavior(.viewAligned)
            .scrollIndicators(.hidden)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
        }
    }
}

private struct CategoryTile: View {
    let category: StoreCategory

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: category.systemImage)
                .font(.system(size: 17, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: 40, height: 40)
                .background(category.artwork.color.gradient, in: .rect(cornerRadius: 11, style: .continuous))
            VStack(alignment: .leading, spacing: 1) {
                Text(category.title)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.primary)
                    .lineLimit(1)
                if let count = category.appCount {
                    Text(CatalogView.count(count))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 12)
        .frame(width: 210, height: 64)
        .background(AppPalette.card, in: .rect(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.25), lineWidth: 0.5))
    }
}

extension View {
    /// Pushed screens shared by every tab.
    func storeDestinations() -> some View {
        navigationDestination(for: AppRoute.self) { route in
            AppPage(route: route)
        }
        .navigationDestination(for: StoreCategory.self) { category in
            CategoryAppsView(category: category)
        }
        .navigationDestination(for: AppListRoute.self) { route in
            AppListView(route: route)
        }
    }
}

/// The app page, zooming out of the element that opened it.
private struct AppPage: View {
    let route: AppRoute
    @Environment(\.zoomNamespace) private var zoom

    var body: some View {
        AppDetailView(app: route.app)
            .zoomDestination(route, in: zoom)
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    FeedScreen()
        .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
        .environment(SessionStore(api: api))
        .environment(AppRouter())
}
