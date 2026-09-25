import SwiftUI

/// A titled list shown as columns of three rows that page sideways, with the
/// next column peeking in. The title opens the full list.
struct AppRowsSection: View {
    let title: String
    let apps: [StoreApp]
    private let rowsPerColumn = 3

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            NavigationLink(value: AppListRoute(title: title, apps: apps)) {
                HStack(spacing: 6) {
                    Text(title)
                        .font(.title2.weight(.bold))
                        .foregroundStyle(.primary)
                        .lineLimit(1)
                    Image(systemName: "chevron.right")
                        .font(.title3.weight(.semibold))
                        .foregroundStyle(.secondary)
                }
            }
            .buttonStyle(.plain)
            .padding(.horizontal, AppSpacing.standard)
            .accessibilityHint("Показать все")

            ScrollView(.horizontal) {
                LazyHStack(alignment: .top, spacing: 12) {
                    ForEach(columns.indices, id: \.self) { index in
                        VStack(spacing: 0) {
                            ForEach(Array(columns[index].enumerated()), id: \.element.id) { row, app in
                                StoreAppRow(app: app)
                                if row < columns[index].count - 1 {
                                    Divider().padding(.leading, 76)
                                }
                            }
                        }
                        .containerRelativeFrame(.horizontal) { width, _ in columns.count > 1 ? width - 40 : width }
                    }
                }
                .scrollTargetLayout()
            }
            .scrollTargetBehavior(.viewAligned)
            .scrollIndicators(.hidden)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
        }
    }

    private var columns: [[StoreApp]] {
        stride(from: 0, to: apps.count, by: rowsPerColumn).map { Array(apps[$0 ..< min($0 + rowsPerColumn, apps.count)]) }
    }
}

/// The full list behind a section title.
struct AppListRoute: Hashable {
    let title: String
    let apps: [StoreApp]
}

struct AppListView: View {
    let route: AppListRoute

    var body: some View {
        List(route.apps) { app in
            StoreAppRow(app: app)
                .listRowBackground(AppPalette.canvas)
        }
        .listStyle(.plain)
        .navigationTitle(route.title)
        .navigationBarTitleDisplayMode(.inline)
    }
}
