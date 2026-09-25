import SwiftUI

@Observable
final class TodayModel {
    private(set) var state: LoadState<FeedDTO> = .loading
    private(set) var isStale = false

    func load(_ catalog: CatalogRepository) async {
        do {
            let feed = try await catalog.feed()
            isStale = feed.isStale
            state = feed.value.sections.isEmpty ? .empty : .loaded(feed.value)
        } catch is CancellationError {
            return
        } catch {
            state = LoadState(error: error)
        }
    }
}

struct TodayView: View {
    @Environment(\.catalog) private var catalog
    @Environment(AppRouter.self) private var router
    @State private var model = TodayModel()

    var body: some View {
        NavigationStack {
            StateContainerView(state: model.state, retry: reload) { feed in
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: AppSpacing.generous) {
                        if model.isStale {
                            StaleDataBanner()
                        }
                        ForEach(feed.sections) { section in
                            FeedSectionView(section: section)
                        }
                    }
                    .padding(.horizontal, AppSpacing.standard)
                    .padding(.bottom, AppSpacing.generous)
                }
                .refreshable { await model.load(catalog) }
            }
            .background(AppPalette.canvas)
            .navigationTitle("Сегодня")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        router.selectedTab = .account
                    } label: {
                        Image(systemName: "person.crop.circle")
                            .font(.title2)
                            .foregroundStyle(AppPalette.accent)
                    }
                    .accessibilityLabel("Аккаунт")
                }
            }
            .navigationDestination(for: StoreApp.self) { app in
                AppDetailView(app: app)
            }
            .navigationDestination(for: StoreCategory.self) { category in
                CategoryAppsView(category: category)
            }
            .task { await model.load(catalog) }
        }
    }

    private func reload() {
        Task { await model.load(catalog) }
    }
}

/// One server-defined feed section. Unknown kinds are skipped (docs/api/openapi.yaml).
struct FeedSectionView: View {
    let section: FeedSectionDTO

    var body: some View {
        switch section.kind {
        case "featured":
            featured
        case "carousel":
            carousel
        case "categories":
            categories
        default:
            EmptyView()
        }
    }

    private var apps: [StoreApp] {
        (section.apps ?? []).map(StoreApp.init)
    }

    @ViewBuilder
    private var featured: some View {
        let apps = apps
        if let hero = apps.first {
            ScrollView(.horizontal) {
                LazyHStack(spacing: AppSpacing.standard) {
                    FeaturedAppCard(app: hero, eyebrow: section.title)
                    ForEach(apps.dropFirst()) { app in
                        HorizontalAppCard(app: app, eyebrow: section.title)
                            .frame(height: 400)
                    }
                }
            }
            .scrollIndicators(.hidden)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
            .padding(.horizontal, -AppSpacing.standard)
        }
    }

    private var carousel: some View {
        VStack(alignment: .leading, spacing: AppSpacing.standard) {
            SectionTitleView(title: LocalizedStringKey(section.title), actionTitle: nil)
            AppRowList(apps: Array(apps.prefix(6)))
        }
    }

    private var categories: some View {
        VStack(alignment: .leading, spacing: AppSpacing.standard) {
            SectionTitleView(title: LocalizedStringKey(section.title), actionTitle: nil)
            ScrollView(.horizontal) {
                LazyHStack(spacing: 12) {
                    ForEach((section.categories ?? []).map(StoreCategory.init)) { category in
                        NavigationLink(value: category) {
                            CategoryCard(category: category)
                        }
                        .buttonStyle(.plain)
                        .accessibilityIdentifier("category-\(category.id)")
                    }
                }
            }
            .scrollIndicators(.hidden)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
            .padding(.horizontal, -AppSpacing.standard)
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    TodayView()
        .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
        .environment(AppRouter())
}
