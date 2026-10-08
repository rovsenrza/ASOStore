import SwiftUI

/// Менеджер: every app prepared for or delivered to this iPhone, with the
/// states the server can observe. «Загружено» is the last one: iOS does not
/// report whether the install finished (IMPLEMENTATION_PLAN G13).
struct ManagerView: View {
    enum Filter: String, CaseIterable, Identifiable {
        case all, active, delivered
        var id: String { rawValue }
        var title: String {
            switch self {
            case .all: "Все"
            case .active: "В процессе"
            case .delivered: "Загружено"
            }
        }
        var symbol: String {
            switch self {
            case .all: "square.grid.2x2"
            case .active: "arrow.triangle.2.circlepath"
            case .delivered: "checkmark.circle"
            }
        }
    }

    @Environment(\.apiClient) private var api
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?
    @Environment(ImportCenter.self) private var imports: ImportCenter?
    @State private var state: LoadState<[InstallationDTO]> = .loading
    @State private var filter: Filter = .all
    @Namespace private var filterSelection

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    StoreHeader()
                        .padding(.bottom, 20)
                    ManagerImportCards()
                        .padding(.bottom, AppSpacing.generous)
                    ManagerImportsSection()
                        .padding(.bottom, AppSpacing.generous)
                    filters
                        .padding(.bottom, 12)
                    StateContainerView(state: state, retry: { Task { await load() } }) { items in
                        content(items)
                    }
                    .frame(minHeight: 360, alignment: .top)
                }
                .padding(.bottom, AppSpacing.generous)
            }
            .background { BrandBackdrop() }
            .background(AppPalette.canvas)
            .toolbarVisibility(.hidden, for: .navigationBar)
            .swipeBackEnabled()
            .refreshable { await load() }
            .task { await load() }
            .storeDestinations()
            .alert("Импорт", isPresented: Binding(
                get: { imports?.message != nil },
                set: { if !$0 { imports?.message = nil } }
            )) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(imports?.message ?? "")
            }
            .alert("Импортировать приложение?", isPresented: Binding(
                get: { imports?.incomingFile != nil },
                set: { if !$0, imports?.incomingFile != nil { imports?.declineIncomingFile() } }
            )) {
                Button("Импортировать") { imports?.acceptIncomingFile() }
                Button("Отмена", role: .cancel) { imports?.declineIncomingFile() }
            } message: {
                Text("«\(imports?.incomingFile?.lastPathComponent ?? "")» будет загружен на сервер, проверен и подписан для вашего iPhone. Вы подтверждаете, что имеете право устанавливать это приложение.")
            }
        }
    }

    private var filters: some View {
        HStack(spacing: 2) {
            ForEach(Filter.allCases) { option in
                let isSelected = filter == option
                Button {
                    withAnimation(Motion.select) { filter = option }
                } label: {
                    Label(option.title, systemImage: option.symbol)
                        .font(.subheadline.weight(.semibold))
                        .padding(.horizontal, 14)
                        .frame(height: 36)
                        .foregroundStyle(isSelected ? Color.white : Color.primary)
                        .background {
                            if isSelected {
                                Capsule().fill(AppPalette.accent)
                                    .matchedGeometryEffect(id: "filter", in: filterSelection)
                            }
                        }
                        .contentShape(Capsule())
                }
                .buttonStyle(PressableStyle(scale: 0.95))
                .accessibilityAddTraits(isSelected ? .isSelected : [])
            }
        }
        .padding(4)
        .glassCapsule()
        .sensoryFeedback(.selection, trigger: filter)
        .padding(.horizontal, AppSpacing.standard)
    }

    @ViewBuilder
    private func content(_ items: [InstallationDTO]) -> some View {
        let live = items.map { installations?.installations[$0.app.id] ?? $0 }
        let shown = live.filter { item in
            switch filter {
            case .all: true
            case .active: item.isActive
            case .delivered: item.status == "DELIVERED"
            }
        }

        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("Мои приложения")
                    .font(.title2.weight(.bold))
                Spacer()
                Text("\(shown.count)")
                    .font(.headline)
                    .foregroundStyle(.secondary)
            }
            .padding(.horizontal, AppSpacing.standard)

            if shown.isEmpty {
                ContentUnavailableView {
                    Label(filter == .all ? "Здесь появятся ваши приложения" : "Ничего нет", systemImage: "arrow.down.app")
                } description: {
                    Text("Приложения, которые вы установите через Ru App Store, и их обновления будут показаны на этом экране.")
                }
                .frame(minHeight: 280)
            } else {
                LazyVStack(spacing: 0) {
                    ForEach(shown) { item in
                        ManagerRow(installation: item)
                            .transition(.opacity.combined(with: .move(edge: .top)))
                        Divider().padding(.leading, 76)
                    }
                }
                .padding(.horizontal, AppSpacing.standard)
            }
        }
    }

    private func load() async {
        async let importsRefreshed: Void = refreshImports()
        do {
            let items = try await PreparationRepository(api: api).library()
            // An empty library is content here (the empty message explains it), not an error.
            state = .loaded(items)
        } catch is CancellationError {
        } catch {
            state = LoadState(error: error)
        }
        await importsRefreshed
    }

    private func refreshImports() async {
        await imports?.refresh()
    }
}

private struct ManagerRow: View {
    let installation: InstallationDTO
    @Environment(\.displayScale) private var displayScale

    var body: some View {
        HStack(spacing: 14) {
            CachedImage(url: installation.app.iconUrl, maxPixel: ImagePixels.icon(62, scale: displayScale)) { image in
                image.resizable().scaledToFill()
            } placeholder: {
                Image(systemName: "app.fill")
                    .font(.title)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .background(AppPalette.accent.gradient)
            }
            .frame(width: 62, height: 62)
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(alignment: .bottomTrailing) {
                if installation.status == "DELIVERED" {
                    Image(systemName: "checkmark.circle.fill")
                        .font(.system(size: 20))
                        .foregroundStyle(.white, AppPalette.success)
                        .offset(x: 5, y: 5)
                }
            }
            .accessibilityHidden(true)

            VStack(alignment: .leading, spacing: 3) {
                Text(installation.app.name)
                    .font(.body.weight(.medium))
                Text(details)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                Text(statusText)
                    .font(.footnote.weight(.medium))
                    .foregroundStyle(statusColor)
            }
            .frame(maxWidth: .infinity, alignment: .leading)

            AppActionButton(app: storeApp)
        }
        .frame(minHeight: 82)
        .accessibilityElement(children: .combine)
    }

    /// Enough of a StoreApp for the CTA, which follows the live installation.
    private var storeApp: StoreApp {
        StoreApp(
            id: installation.app.id, name: installation.app.name, subtitle: "", category: "", developer: "",
            systemImage: "app.fill", artwork: .derived(from: installation.app.id),
            installState: installation.installState, iconURL: installation.app.iconUrl
        )
    }

    private var details: String {
        [installation.version.map { "v\($0)" }, installation.buildNumber.map { "сборка \($0)" }].compactMap { $0 }.joined(separator: " · ")
    }

    private var statusText: String {
        switch installation.status {
        case "PREPARING": "Подготовка · \(InstallStage(progress: installation.preparation.progress).title)"
        case "READY_TO_INSTALL", "AUTHORIZED": "Готово к установке"
        case "MANIFEST_FETCHED": "Загружается"
        case "DELIVERED": "Загружено — проверьте экран «Домой»"
        case "EXPIRED": "Ссылка устарела"
        default: "Не удалось подготовить"
        }
    }

    private var statusColor: Color {
        switch installation.status {
        case "DELIVERED", "READY_TO_INSTALL": AppPalette.success
        case "FAILED", "EXPIRED": .red
        default: .secondary
        }
    }
}

#Preview {
    ManagerView()
        .environment(\.apiClient, APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock)))
        .environment(AppRouter())
}
