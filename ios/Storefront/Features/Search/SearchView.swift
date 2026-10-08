import SwiftUI

@Observable
final class SearchModel {
    enum Listing: String, CaseIterable, Identifiable {
        case updated, new
        var id: String { rawValue }
        var title: String { self == .updated ? "Обновлено" : "Новое" }
        var sort: CatalogSort { self == .updated ? .updated : .new }
    }

    var query = ""
    var listing: Listing = .updated
    private(set) var results: LoadState<[StoreApp]> = .empty
    /// The two browse lists shown while nothing is typed, with their totals.
    private(set) var lists: [Listing: LoadState<[StoreApp]>] = [:]
    private(set) var totals: [Listing: Int] = [:]

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

    func loadLists(_ catalog: CatalogRepository) async {
        for listing in Listing.allCases where lists[listing] == nil {
            lists[listing] = .loading
        }
        async let updated = Self.fetch(catalog, .updated)
        async let newest = Self.fetch(catalog, .new)
        for (listing, result) in [(Listing.updated, await updated), (Listing.new, await newest)] {
            switch result {
            case .success(let page):
                totals[listing] = page.total
                lists[listing] = page.apps.isEmpty ? .empty : .loaded(page.apps.map(StoreApp.init))
            case .failure(let error):
                if !(error is CancellationError) { lists[listing] = LoadState(error: error) }
            }
        }
    }

    private static func fetch(_ catalog: CatalogRepository, _ listing: Listing) async -> Result<(apps: [AppSummaryDTO], total: Int), Error> {
        do {
            return .success(try await catalog.page(sort: listing.sort))
        } catch {
            return .failure(error)
        }
    }
}

struct SearchView: View {
    @Environment(\.catalog) private var catalog
    @State private var model = SearchModel()
    @FocusState private var searching: Bool
    @Namespace private var zoom

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    StoreHeader()
                    searchField
                    if isSearching {
                        searchResults
                    } else {
                        listingPicker
                        StateContainerView(state: model.lists[model.listing] ?? .loading, retry: reloadLists, skeleton: .rows) { apps in
                            rows(apps)
                        }
                        .frame(minHeight: 300, alignment: .top)
                    }
                }
                .padding(.bottom, AppSpacing.generous)
            }
            .scrollDismissesKeyboard(.immediately)
            .background { BrandBackdrop() }
            .background(AppPalette.canvas)
            .toolbarVisibility(.hidden, for: .navigationBar)
            .swipeBackEnabled()
            .refreshable { await model.loadLists(catalog) }
            .task(id: model.query) { await model.search(catalog) }
            .task { await model.loadLists(catalog) }
            .environment(\.zoomNamespace, zoom)
            .storeDestinations()
        }
    }

    private var isSearching: Bool {
        !model.query.trimmingCharacters(in: .whitespaces).isEmpty
    }

    private var searchField: some View {
        HStack(spacing: 10) {
            Image(systemName: "magnifyingglass")
                .foregroundStyle(.secondary)
            TextField("Игры, приложения, издатели", text: $model.query)
                .focused($searching)
                .submitLabel(.search)
                .autocorrectionDisabled()
                .accessibilityIdentifier("search-field")
            if !model.query.isEmpty {
                Button {
                    model.query = ""
                } label: {
                    Image(systemName: "xmark.circle.fill").foregroundStyle(.secondary)
                }
                .accessibilityLabel("Очистить")
            }
        }
        .padding(.horizontal, 16)
        .frame(height: 50)
        .glassCapsule()
        .padding(.horizontal, AppSpacing.standard)
    }

    /// «Обновлено» / «Новое» with the real totals from the API.
    private var listingPicker: some View {
        HStack(spacing: 4) {
            ForEach(SearchModel.Listing.allCases) { listing in
                Button {
                    withAnimation(.snappy) { model.listing = listing }
                } label: {
                    HStack(spacing: 8) {
                        Text(listing.title)
                            .font(.headline)
                        if let total = model.totals[listing] {
                            Text("\(total)")
                                .font(.caption.weight(.bold))
                                .padding(.horizontal, 7)
                                .padding(.vertical, 2)
                                .background(model.listing == listing ? AppPalette.accent : AppPalette.ctaFill, in: Capsule())
                                .foregroundStyle(model.listing == listing ? .white : .secondary)
                        }
                    }
                    .frame(maxWidth: .infinity, minHeight: 44)
                    .foregroundStyle(model.listing == listing ? .primary : .secondary)
                    .background {
                        if model.listing == listing {
                            Capsule().fill(AppPalette.ctaFill)
                        }
                    }
                }
                .buttonStyle(.plain)
                .accessibilityAddTraits(model.listing == listing ? .isSelected : [])
            }
        }
        .padding(4)
        .glassCapsule()
        .padding(.horizontal, AppSpacing.standard)
    }

    @ViewBuilder
    private var searchResults: some View {
        switch model.results {
        case .empty:
            ContentUnavailableView.search(text: model.query)
                .frame(minHeight: 300)
        default:
            StateContainerView(state: model.results, retry: retrySearch, skeleton: .rows) { apps in
                rows(apps)
            }
            .frame(minHeight: 300, alignment: .top)
        }
    }

    private func rows(_ apps: [StoreApp]) -> some View {
        LazyVStack(spacing: 0) {
            ForEach(apps) { app in
                StoreAppRow(app: app, source: "search")
                    .scrollReveal()
                Divider().padding(.leading, 76)
            }
        }
        .padding(.horizontal, AppSpacing.standard)
    }

    private func retrySearch() {
        Task { await model.search(catalog) }
    }

    private func reloadLists() {
        Task { await model.loadLists(catalog) }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    SearchView()
        .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
        .environment(AppRouter())
}
