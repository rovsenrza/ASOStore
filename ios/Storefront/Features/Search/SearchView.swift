import Combine
import SwiftUI

final class SearchModel: ObservableObject {
    enum Listing: String, CaseIterable, Identifiable {
        case updated, new
        var id: String { rawValue }
        var title: String { self == .updated ? "Обновлено" : "Новое" }
    }

    @Published var query = ""
    @Published var listing: Listing = .updated

    /// What is being searched for, or nil while the field is empty.
    var term: String? {
        let term = query.trimmingCharacters(in: .whitespacesAndNewlines)
        return term.isEmpty ? nil : term
    }

    /// Debounced: typing cancels the previous search (FULL_PLAN §11 "search remains responsive").
    /// The results are a paged list like Каталог, so they keep loading as the list is scrolled.
    func search(_ catalog: CatalogRepository, into results: CatalogListModel) async {
        guard let term else { return }
        do {
            try await Task.sleep(for: .milliseconds(300))
        } catch {
            return
        }
        results.filter.search = term
        await results.reload(catalog, fresh: true)
    }
}

struct SearchView: View {
    @Environment(\.catalog) private var catalog
    @StateObject private var model = SearchModel()
    /// «Обновлено» and «Новое»: the whole catalog in that order, a page at a time.
    @StateObject private var updated = CatalogListModel(sort: .updated)
    @StateObject private var newest = CatalogListModel(sort: .new)
    /// What the typed query found, a page at a time.
    @StateObject private var results = CatalogListModel()
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
                        CatalogAppList(model: list(model.listing), source: "search-\(model.listing.rawValue)") {
                            reloadLists()
                        }
                        .padding(.horizontal, AppSpacing.standard)
                        .frame(minHeight: 300, alignment: .top)
                    }
                }
                .padding(.bottom, AppSpacing.generous)
            }
            .scrollDismissesKeyboard(.immediately)
            .background { BrandBackdrop() }
            .background(AppPalette.canvas)
            .navigationBarHiddenCompat()
            .swipeBackEnabled()
            .refreshable { await reloadAll() }
            .task(id: model.term) { await model.search(catalog, into: results) }
            .task { await reloadAll() }
            .environment(\.zoomNamespace, zoom)
            .storeDestinations()
        }
    }

    private var isSearching: Bool {
        model.term != nil
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
        .frame(minHeight: 50)
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
                        if let total = list(listing).total {
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
        // Typing has not reached the server yet (debounce): the list still answers the old term.
        if results.filter.search != model.term {
            SkeletonRows()
                .padding(.horizontal, AppSpacing.standard)
                .frame(minHeight: 300, alignment: .top)
        } else if case .empty = results.state {
            SearchUnavailableView(text: model.query)
                .frame(minHeight: 300)
        } else {
            CatalogAppList(model: results, source: "search", retry: retrySearch)
                .padding(.horizontal, AppSpacing.standard)
                .frame(minHeight: 300, alignment: .top)
        }
    }

    private func retrySearch() {
        Task { await results.reload(catalog, fresh: true) }
    }

    private func list(_ listing: SearchModel.Listing) -> CatalogListModel {
        listing == .updated ? updated : newest
    }

    private func reloadLists() {
        Task { await reloadAll() }
    }

    private func reloadAll() async {
        async let first: Void = updated.reload(catalog)
        async let second: Void = newest.reload(catalog)
        _ = await (first, second)
    }
}

#Preview {
    SearchView()
        .previewEnvironment()
}
