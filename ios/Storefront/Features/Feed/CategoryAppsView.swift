import SwiftUI

/// Every published app of one category.
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
                    LazyVStack(spacing: 0) {
                        ForEach(apps) { app in
                            StoreAppRow(app: app)
                            Divider().padding(.leading, 76)
                        }
                    }
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

