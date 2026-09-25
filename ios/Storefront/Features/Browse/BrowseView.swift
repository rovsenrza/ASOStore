import SwiftUI

@Observable
final class BrowseModel {
    struct Content: Sendable {
        let featured: [StoreApp]
        let categories: [StoreCategory]
        let apps: [StoreApp]
    }

    private(set) var state: LoadState<Content> = .loading
    private(set) var isStale = false

    func load(_ catalog: CatalogRepository) async {
        do {
            async let feed = catalog.feed()
            async let all = catalog.apps()
            let (feedResult, appsResult) = try await (feed, all)
            let sections = feedResult.value.sections
            let content = Content(
                featured: (sections.first { $0.kind == "featured" }?.apps ?? []).map(StoreApp.init),
                categories: (sections.first { $0.kind == "categories" }?.categories ?? []).map(StoreCategory.init),
                apps: appsResult.value.map(StoreApp.init)
            )
            isStale = feedResult.isStale || appsResult.isStale
            state = content.apps.isEmpty && content.categories.isEmpty ? .empty : .loaded(content)
        } catch is CancellationError {
            return
        } catch {
            state = LoadState(error: error)
        }
    }
}

struct BrowseView: View {
    @Environment(\.catalog) private var catalog
    @State private var model = BrowseModel()

    var body: some View {
        NavigationStack {
            StateContainerView(state: model.state, retry: reload) { content in
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 30) {
                        if model.isStale {
                            StaleDataBanner().padding(.horizontal, AppSpacing.standard)
                        }
                        if !content.featured.isEmpty {
                            featured(content.featured)
                        }
                        if !content.categories.isEmpty {
                            categories(content.categories)
                        }
                        VStack(alignment: .leading, spacing: 14) {
                            SectionTitleView(title: "Все приложения", actionTitle: nil)
                            AppRowList(apps: content.apps)
                        }
                        .padding(.horizontal, AppSpacing.standard)
                    }
                    .padding(.bottom, 36)
                }
                .refreshable { await model.load(catalog) }
            }
            .background(AppPalette.canvas)
            .navigationTitle("Приложения")
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

    private func featured(_ apps: [StoreApp]) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            Text("Наша подборка")
                .font(.caption.weight(.bold))
                .foregroundStyle(AppPalette.accent)
                .textCase(.uppercase)
                .padding(.horizontal, AppSpacing.standard)

            ScrollView(.horizontal) {
                LazyHStack(spacing: AppSpacing.standard) {
                    ForEach(apps) { app in
                        HorizontalAppCard(app: app, eyebrow: app.category)
                    }
                }
            }
            .scrollTargetBehavior(.viewAligned)
            .scrollIndicators(.hidden)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
        }
    }

    private func categories(_ categories: [StoreCategory]) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionTitleView(title: "Категории", actionTitle: nil)
                .padding(.horizontal, AppSpacing.standard)

            ScrollView(.horizontal) {
                LazyHStack(spacing: 12) {
                    ForEach(categories) { category in
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
        }
    }
}

/// Apps in one category.
struct CategoryAppsView: View {
    let category: StoreCategory
    @Environment(\.catalog) private var catalog
    @State private var state: LoadState<[StoreApp]> = .loading
    @State private var isStale = false

    var body: some View {
        StateContainerView(state: state, retry: reload) { apps in
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    if isStale {
                        StaleDataBanner()
                    }
                    if !category.subtitle.isEmpty {
                        Text(category.subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    AppRowList(apps: apps)
                }
                .padding(.horizontal, AppSpacing.standard)
                .padding(.bottom, 36)
            }
        }
        .background(AppPalette.canvas)
        .navigationTitle(category.title)
        .task { await load() }
    }

    private func reload() {
        Task { await load() }
    }

    private func load() async {
        do {
            let result = try await catalog.apps(category: category.id)
            isStale = result.isStale
            state = result.value.isEmpty ? .empty : .loaded(result.value.map(StoreApp.init))
        } catch is CancellationError {
            return
        } catch {
            state = LoadState(error: error)
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    BrowseView()
        .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
}
