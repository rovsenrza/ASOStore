import SwiftUI

@Observable
final class SearchModel {
    var query = ""
    private(set) var results: LoadState<[StoreApp]> = .empty
    private(set) var categories: [StoreCategory] = []

    /// Debounced: typing cancels the previous search (FULL_PLAN §11 "search remains responsive").
    func search(_ catalog: CatalogRepository) async {
        let term = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !term.isEmpty else {
            results = .empty
            return
        }

        do {
            try await Task.sleep(for: .milliseconds(300))
            results = .loading
            let apps = try await catalog.search(term)
            try Task.checkCancellation()
            results = apps.isEmpty ? .empty : .loaded(apps.map(StoreApp.init))
        } catch is CancellationError {
            return
        } catch {
            results = LoadState(error: error)
        }
    }

    func loadSuggestions(_ catalog: CatalogRepository) async {
        guard categories.isEmpty, let feed = try? await catalog.feed() else { return }
        categories = (feed.value.sections.first { $0.kind == "categories" }?.categories ?? []).map(StoreCategory.init)
    }
}

struct SearchView: View {
    @Environment(\.catalog) private var catalog
    @State private var model = SearchModel()

    var body: some View {
        NavigationStack {
            Group {
                if model.query.trimmingCharacters(in: .whitespaces).isEmpty {
                    suggestions
                } else {
                    switch model.results {
                    case .empty:
                        ContentUnavailableView.search(text: model.query)
                    default:
                        StateContainerView(state: model.results, retry: retry) { apps in
                            ScrollView {
                                AppRowList(apps: apps)
                                    .padding(.horizontal, AppSpacing.standard)
                                    .padding(.bottom, 36)
                            }
                        }
                    }
                }
            }
            .background(AppPalette.canvas)
            .navigationTitle("Поиск")
            .searchable(text: $model.query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Приложения, издатели, категории")
            .task(id: model.query) { await model.search(catalog) }
            .task { await model.loadSuggestions(catalog) }
            .navigationDestination(for: StoreApp.self) { app in
                AppDetailView(app: app)
            }
            .navigationDestination(for: StoreCategory.self) { category in
                CategoryAppsView(category: category)
            }
        }
    }

    private func retry() {
        Task { await model.search(catalog) }
    }

    private var suggestions: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 12) {
                if !model.categories.isEmpty {
                    Text("Категории")
                        .font(.title2.bold())
                    ForEach(model.categories) { category in
                        NavigationLink(value: category) {
                            HStack(spacing: 12) {
                                Image(systemName: category.systemImage)
                                    .foregroundStyle(category.artwork.color)
                                    .frame(width: 28)
                                Text(category.title)
                                    .foregroundStyle(.primary)
                                Spacer()
                                Image(systemName: "chevron.right")
                                    .font(.caption.bold())
                                    .foregroundStyle(.tertiary)
                            }
                            .frame(minHeight: 44)
                        }
                        .buttonStyle(.plain)
                        Divider()
                    }
                }
            }
            .padding(.horizontal, AppSpacing.standard)
            .padding(.bottom, 36)
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    SearchView()
        .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
}
