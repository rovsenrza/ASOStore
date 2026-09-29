import SwiftUI

/// Every published app of one category, a page at a time, in the order the customer picks.
struct CategoryAppsView: View {
    let category: StoreCategory
    @Environment(\.catalog) private var catalog
    @State private var model: CatalogListModel

    init(category: StoreCategory) {
        self.category = category
        let model = CatalogListModel()
        model.filter.category = category.id
        _model = State(initialValue: model)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                if !category.subtitle.isEmpty {
                    Text(category.subtitle)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                CatalogAppList(model: model, source: "category-\(category.id)") {
                    Task { await model.reload(catalog) }
                }
            }
            .padding(.horizontal, AppSpacing.standard)
            .padding(.bottom, 36)
        }
        .background(AppPalette.canvas)
        .navigationTitle(category.title)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                SortMenu(sort: $model.filter.sort, inToolbar: true)
            }
        }
        .refreshable { await model.reload(catalog) }
        .task(id: model.filter) { await model.reload(catalog) }
    }
}
