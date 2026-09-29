import SwiftUI

/// Каталог: every published app in one list. Kind, category and order narrow it down;
/// nothing is hidden behind a shelf, so browsing works without search.
struct CatalogView: View {
    @Environment(\.catalog) private var catalog
    @Environment(SessionStore.self) private var session
    @Environment(AppRouter.self) private var router
    @State private var model = CatalogListModel()
    @State private var path = NavigationPath()
    @State private var categories: [StoreCategory] = []
    /// The filters stick to the top once the title has scrolled away.
    @State private var filtersPinned = false
    @Namespace private var zoom

    var body: some View {
        NavigationStack(path: $path) {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 0, pinnedViews: .sectionHeaders) {
                    StoreHeader()
                        .padding(.bottom, 14)
                    title
                        .padding(.horizontal, AppSpacing.standard)
                        .padding(.bottom, 10)
                        .onGeometryChange(for: Bool.self) { $0.frame(in: .scrollView).maxY < 0 } action: { pinned in
                            withAnimation(.easeOut(duration: 0.2)) { filtersPinned = pinned }
                        }

                    Section {
                        CatalogAppList(model: model, source: "catalog", retry: reload)
                            .padding(.horizontal, AppSpacing.standard)
                    } header: {
                        CatalogFilterBar(filter: $model.filter, categories: categories, isPinned: filtersPinned)
                    }
                }
                .padding(.bottom, AppSpacing.generous)
            }
            .background { BrandBackdrop() }
            .background(AppPalette.canvas)
            .refreshable { await model.reload(catalog) }
            .toolbarVisibility(.hidden, for: .navigationBar)
            .environment(\.zoomNamespace, zoom)
            .storeDestinations()
            .task(id: CatalogReloadKey(filter: model.filter, user: session.user?.id)) { await model.reload(catalog) }
            .task { await loadCategories() }
            .onChange(of: router.requestedAppID, initial: true) { _, id in
                guard let id else { return }
                path.append(AppRoute(.loading(id: id), from: AppRoute.link))
                router.requestedAppID = nil
            }
        }
    }

    private var title: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("Каталог")
                .font(.largeTitle.bold())
            Group {
                if let total = model.total {
                    Text(Self.count(total))
                        .contentTransition(.numericText(value: Double(total)))
                } else {
                    Text(" ")
                }
            }
            .font(.subheadline)
            .foregroundStyle(.secondary)
            .animation(Motion.state, value: model.total)
        }
        .accessibilityElement(children: .combine)
    }

    private func reload() {
        Task { await model.reload(catalog) }
    }

    private func loadCategories() async {
        guard categories.isEmpty, let feed = try? await catalog.feed(.all) else { return }
        categories = feed.value.sections.flatMap { $0.categories ?? [] }.map(StoreCategory.init)
    }

    /// «223 приложения»: Russian plural forms.
    static func count(_ total: Int) -> String {
        let tens = total % 100, ones = total % 10
        let word = (11...14).contains(tens) ? "приложений" : ones == 1 ? "приложение" : (2...4).contains(ones) ? "приложения" : "приложений"
        return "\(total) \(word)"
    }
}

/// Reload when the filter changes, and when the account does (install states depend on it).
struct CatalogReloadKey: Equatable {
    let filter: CatalogListModel.Filter
    let user: String?
}

// MARK: - Filters

/// Pinned above the list: kind, order and category. Glass, so the list shows through as it
/// scrolls under.
struct CatalogFilterBar: View {
    @Binding var filter: CatalogListModel.Filter
    let categories: [StoreCategory]
    /// Stuck to the top over the list: only then does it need a backing.
    var isPinned = false
    @Namespace private var selection

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                kindPicker
                Spacer(minLength: 0)
                SortMenu(sort: $filter.sort)
            }
            .padding(.horizontal, AppSpacing.standard)

            if !visibleCategories.isEmpty {
                categoryChips
            }
        }
        .padding(.vertical, 10)
        .background {
            // Content scrolling under the bar fades out instead of colliding with the chips.
            LinearGradient(stops: [.init(color: AppPalette.canvas, location: 0.55), .init(color: AppPalette.canvas.opacity(0), location: 1)], startPoint: .top, endPoint: .bottom)
                .padding(.bottom, -16)
                .ignoresSafeArea(edges: .top)
                .opacity(isPinned ? 1 : 0)
        }
        .onChange(of: filter.kind) {
            // A games category makes no sense under «Приложения».
            if let category = filter.category, !visibleCategories.contains(where: { $0.id == category }) {
                filter.category = nil
            }
        }
    }

    private var kindPicker: some View {
        HStack(spacing: 2) {
            ForEach([CatalogKind.all, .apps, .games], id: \.self) { kind in
                let isSelected = filter.kind == kind
                Button {
                    withAnimation(Motion.select) { filter.kind = kind }
                } label: {
                    Text(Self.title(kind))
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(isSelected ? Color.white : Color.primary)
                        .padding(.horizontal, 14)
                        .frame(height: 36)
                        .background {
                            if isSelected {
                                Capsule().fill(AppPalette.accent)
                                    .matchedGeometryEffect(id: "kind", in: selection)
                            }
                        }
                        .contentShape(Capsule())
                }
                .buttonStyle(PressableStyle(scale: 0.95))
                .accessibilityAddTraits(isSelected ? .isSelected : [])
                .accessibilityIdentifier("kind-\(kind.rawValue)")
            }
        }
        .padding(4)
        .glassCapsule()
        .sensoryFeedback(.selection, trigger: filter.kind)
    }

    private var categoryChips: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 8) {
                chip(title: "Все категории", systemImage: nil, isSelected: filter.category == nil) {
                    filter.category = nil
                }
                .accessibilityIdentifier("category-all")
                ForEach(visibleCategories) { category in
                    chip(title: category.title, systemImage: category.systemImage, isSelected: filter.category == category.id) {
                        filter.category = filter.category == category.id ? nil : category.id
                    }
                    .accessibilityIdentifier("category-\(category.id)")
                }
            }
            .padding(.vertical, 2)
        }
        .scrollIndicators(.hidden)
        .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
        .sensoryFeedback(.selection, trigger: filter.category)
    }

    private func chip(title: String, systemImage: String?, isSelected: Bool, action: @escaping () -> Void) -> some View {
        Button {
            withAnimation(Motion.select, action)
        } label: {
            HStack(spacing: 6) {
                if let systemImage {
                    Image(systemName: systemImage)
                        .font(.footnote.weight(.semibold))
                }
                Text(title)
                    .font(.subheadline.weight(.medium))
            }
            .foregroundStyle(isSelected ? Color.white : Color.primary)
            .padding(.horizontal, 14)
            .frame(height: 36)
            .modifier(ChipFace(isSelected: isSelected))
        }
        .buttonStyle(PressableStyle(scale: 0.94))
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }

    private var visibleCategories: [StoreCategory] {
        switch filter.kind {
        case .all: categories
        case .apps: categories.filter { $0.kind == nil || $0.kind == "APPS" }
        case .games: categories.filter { $0.kind == nil || $0.kind == "GAMES" }
        }
    }

    static func title(_ kind: CatalogKind) -> String {
        switch kind {
        case .all: "Все"
        case .apps: "Приложения"
        case .games: "Игры"
        }
    }
}

private struct ChipFace: ViewModifier {
    let isSelected: Bool

    func body(content: Content) -> some View {
        if isSelected {
            content.glassCapsule(tint: AppPalette.accent)
        } else {
            content.glassCapsule()
        }
    }
}

/// Order of the list, from a glass button.
struct SortMenu: View {
    @Binding var sort: CatalogSort
    /// The toolbar draws its own glass around the button.
    var inToolbar = false

    var body: some View {
        Menu {
            Picker("Сортировка", selection: $sort) {
                ForEach(CatalogSort.allCases, id: \.self) { option in
                    Text(option.title).tag(option)
                }
            }
        } label: {
            if inToolbar {
                Image(systemName: "arrow.up.arrow.down")
            } else {
                Image(systemName: "arrow.up.arrow.down")
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundStyle(.primary)
                    .frame(width: 44, height: 44)
                    .glassCircle()
            }
        }
        .accessibilityLabel("Сортировка: \(sort.title)")
        .sensoryFeedback(.selection, trigger: sort)
    }
}

// MARK: - List

/// The rows of a CatalogListModel, loading the next page as the end comes into view.
struct CatalogAppList: View {
    let model: CatalogListModel
    /// Names the list for zoom transitions.
    let source: String
    let retry: () -> Void
    @Environment(\.catalog) private var catalog

    var body: some View {
        switch model.state {
        case .loading:
            SkeletonRows()
                .transition(.opacity)
        case .loaded(let apps):
            LazyVStack(spacing: 0) {
                ForEach(apps) { app in
                    StoreAppRow(app: app, source: source)
                        .scrollReveal()
                        .onAppear {
                            Task { await model.loadMore(after: app, catalog) }
                        }
                    Divider().padding(.leading, 76)
                }
                footer
            }
        default:
            StateContainerView(state: model.state, retry: retry) { _ in EmptyView() }
                .frame(minHeight: 320)
        }
    }

    @ViewBuilder
    private var footer: some View {
        switch model.more {
        case .loading:
            SkeletonRows(count: 2)
        case .failed:
            Button {
                Task { await model.loadNextPage(catalog) }
            } label: {
                Label("Не удалось загрузить ещё. Повторить", systemImage: "arrow.clockwise")
                    .font(.subheadline.weight(.semibold))
                    .padding(.horizontal, 18)
                    .frame(height: 44)
                    .glassCapsule()
            }
            .buttonStyle(PressableStyle())
            .frame(maxWidth: .infinity)
            .padding(.vertical, 20)
        case .exhausted:
            if let total = model.total, total > 8 {
                Text("Это все: \(CatalogView.count(total))")
                    .font(.footnote)
                    .foregroundStyle(.tertiary)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 24)
            }
        case .idle:
            EmptyView()
        }
    }
}
