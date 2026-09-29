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
}

/// Главная, Игры and Приложения: the same layout over a different part of the catalog.
struct FeedScreen: View {
    let kind: CatalogKind
    @Environment(\.catalog) private var catalog
    @Environment(SessionStore.self) private var session
    @State private var model = FeedModel()

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 30) {
                    StoreHeader()
                    StateContainerView(state: model.state, retry: reload) { feed in
                        VStack(alignment: .leading, spacing: 30) {
                            if model.isStale {
                                StaleDataBanner().padding(.horizontal, AppSpacing.standard)
                            }
                            ForEach(feed.sections) { section in
                                FeedSectionView(section: section)
                            }
                        }
                    }
                    .frame(minHeight: 420)
                }
                .padding(.bottom, AppSpacing.generous)
            }
            .background { BrandBackdrop() }
            .background(AppPalette.canvas)
            .refreshable { await model.load(catalog, kind: kind) }
            .toolbarVisibility(.hidden, for: .navigationBar)
            .storeDestinations()
            // Install buttons depend on who is signed in: reload when that changes.
            .task(id: session.user?.id) { await model.load(catalog, kind: kind) }
        }
    }

    private func reload() {
        Task { await model.load(catalog, kind: kind) }
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
            categories
        default:
            EmptyView()
        }
    }

    private var apps: [StoreApp] {
        (section.apps ?? []).map(StoreApp.init)
    }

    private var categories: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(section.title)
                .font(.title2.weight(.bold))
                .padding(.horizontal, AppSpacing.standard)
            ScrollView(.horizontal) {
                LazyHStack(spacing: 10) {
                    ForEach((section.categories ?? []).map(StoreCategory.init)) { category in
                        NavigationLink(value: category) {
                            Label(category.title, systemImage: category.systemImage)
                                .font(.subheadline.weight(.semibold))
                                .padding(.horizontal, 16)
                                .frame(height: 40)
                                .background(AppPalette.ctaFill, in: Capsule())
                                .foregroundStyle(.primary)
                        }
                        .buttonStyle(.plain)
                        .accessibilityIdentifier("category-\(category.id)")
                    }
                }
            }
            .scrollIndicators(.hidden)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
        }
    }
}

extension View {
    /// Pushed screens shared by every tab.
    func storeDestinations() -> some View {
        navigationDestination(for: StoreApp.self) { app in
            AppDetailView(app: app)
        }
        .navigationDestination(for: StoreCategory.self) { category in
            CategoryAppsView(category: category)
        }
        .navigationDestination(for: AppListRoute.self) { route in
            AppListView(route: route)
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    FeedScreen(kind: .all)
        .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
        .environment(SessionStore(api: api))
        .environment(AppRouter())
}
