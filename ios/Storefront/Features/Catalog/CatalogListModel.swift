import Foundation
import Observation

/// The full catalog as one list, a page at a time: Каталог and «Все приложения» on Главная.
@Observable
final class CatalogListModel {
    struct Filter: Equatable {
        var kind = CatalogKind.all
        var category: String?
        var sort = CatalogSort.featured
    }

    enum More: Equatable {
        case idle, loading, failed, exhausted
    }

    var filter = Filter()
    /// The first page: drives the screen's loading, empty and error states.
    private(set) var state: LoadState<[StoreApp]> = .loading
    /// Later pages, loaded as the end of the list comes into view.
    private(set) var more: More = .idle
    private(set) var total: Int?

    private var page = 0
    /// A reply to an older filter must not land in the new list.
    private var generation = 0

    var apps: [StoreApp] { state.value ?? [] }

    /// Retries a load that was cancelled mid-flight without ever producing a result. Pushing
    /// another screen onto the nav stack cancels this view's own `.task`, and nothing restarts
    /// it on return since the reload key didn't change — the list is stuck on its loading
    /// skeleton until a manual pull-to-refresh. Call from `.onAppear` on every appearance
    /// after the first (the first is `.task`'s job; calling this there too would just race it).
    func resumeIfStuck(_ catalog: CatalogRepository) {
        guard case .loading = state else { return }
        Task { await reload(catalog) }
    }

    func reload(_ catalog: CatalogRepository) async {
        generation += 1
        let current = generation
        if state.value == nil { state = .loading }
        more = .idle
        do {
            let result = try await catalog.list(kind: filter.kind, category: filter.category, sort: filter.sort, page: 1)
            guard current == generation else { return }
            page = 1
            total = result.total
            state = result.apps.isEmpty ? .empty : .loaded(result.apps.map(StoreApp.init))
            more = result.hasMore ? .idle : .exhausted
        } catch is CancellationError {
            return
        } catch {
            guard current == generation else { return }
            state = LoadState(error: error)
        }
    }

    /// Called as each row appears: fetches the next page when the last rows show up.
    func loadMore(after app: StoreApp, _ catalog: CatalogRepository) async {
        let apps = apps
        guard more == .idle, let index = apps.firstIndex(of: app), index >= apps.count - 6 else { return }
        await loadNextPage(catalog)
    }

    func loadNextPage(_ catalog: CatalogRepository) async {
        guard more == .idle || more == .failed, case .loaded(let loaded) = state else { return }
        let current = generation
        more = .loading
        do {
            let result = try await catalog.list(kind: filter.kind, category: filter.category, sort: filter.sort, page: page + 1)
            guard current == generation else { return }
            page += 1
            // Listings can shift between pages while someone publishes; never show one twice.
            let known = Set(loaded.map(\.id))
            state = .loaded(loaded + result.apps.map(StoreApp.init).filter { !known.contains($0.id) })
            total = result.total
            more = result.hasMore ? .idle : .exhausted
        } catch is CancellationError {
            if current == generation { more = .idle }
        } catch {
            if current == generation { more = .failed }
        }
    }
}
