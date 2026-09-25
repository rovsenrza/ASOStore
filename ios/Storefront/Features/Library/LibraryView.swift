import SwiftUI

/// Apps prepared or installed on this device, with the states the server can
/// observe. «Загружено» is the last one: iOS does not report whether the
/// install finished (IMPLEMENTATION_PLAN G13).
struct LibraryView: View {
    @Environment(\.apiClient) private var api
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?
    @State private var state: LoadState<[InstallationDTO]> = .loading

    var body: some View {
        NavigationStack {
            StateContainerView(state: state, retry: { Task { await load() } }) { items in
                List(items) { item in
                    LibraryRow(installation: installations?.installations[item.app.id] ?? item)
                }
                .listStyle(.plain)
                .refreshable { await load() }
            }
            .overlay {
                if case .empty = state {
                    ContentUnavailableView {
                        Label("Здесь появятся ваши приложения", systemImage: "square.stack")
                    } description: {
                        Text("Приложения, которые вы установите через Storefront, и доступные для них обновления будут показаны на этом экране.")
                    }
                    .background(AppPalette.canvas)
                }
            }
            .background(AppPalette.canvas)
            .navigationTitle("Медиатека")
            .task { await load() }
        }
    }

    private func load() async {
        do {
            let items = try await PreparationRepository(api: api).library()
            state = items.isEmpty ? .empty : .loaded(items)
        } catch is CancellationError {
        } catch {
            state = LoadState(error: error)
        }
    }
}

private struct LibraryRow: View {
    let installation: InstallationDTO

    var body: some View {
        HStack(spacing: AppSpacing.standard) {
            AsyncImage(url: installation.app.iconUrl) { image in
                image.resizable().scaledToFill()
            } placeholder: {
                Image(systemName: "app.dashed").font(.title).foregroundStyle(.secondary)
            }
            .frame(width: 52, height: 52)
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            .accessibilityHidden(true)

            VStack(alignment: .leading, spacing: 2) {
                Text(installation.app.name).font(.headline)
                if let version = installation.version {
                    Text("Версия \(version)").font(.subheadline).foregroundStyle(.secondary)
                }
                Text(statusText).font(.footnote).foregroundStyle(statusColor)
            }
            Spacer()
            if case .preparing(let progress) = installation.installState {
                ProgressView(value: progress ?? 0).progressViewStyle(.circular)
            }
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .combine)
    }

    private var statusText: String {
        switch installation.status {
        case "PREPARING": "Подготовка для этого iPhone"
        case "READY_TO_INSTALL", "AUTHORIZED": "Готово к установке"
        case "MANIFEST_FETCHED": "Загружается"
        case "DELIVERED": "Загружено — проверьте экран «Домой»"
        case "EXPIRED": "Ссылка устарела"
        default: "Не удалось подготовить"
        }
    }

    private var statusColor: Color {
        switch installation.status {
        case "DELIVERED", "READY_TO_INSTALL": AppPalette.accent
        case "FAILED", "EXPIRED": .red
        default: .secondary
        }
    }
}

#Preview {
    LibraryView()
        .environment(\.apiClient, APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock)))
}
