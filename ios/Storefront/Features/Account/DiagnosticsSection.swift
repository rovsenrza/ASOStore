#if DEBUG
import SwiftUI

/// Debug-only check that the configured API (live or mock) answers.
struct DiagnosticsSection: View {
    @Environment(\.apiClient) private var apiClient
    @State private var health: LoadState<HealthDTO> = .loading
    @State private var environment: APIEnvironment?

    var body: some View {
        Section("Диагностика") {
            LabeledContent("Режим API", value: environment?.mode.rawValue ?? "—")
            LabeledContent("Адрес", value: environment?.baseURL.absoluteString ?? "—")
                .lineLimit(1)
            LabeledContent("Сервер") {
                switch health {
                case .loading:
                    ProgressView()
                case .loaded(let value):
                    Text("Работает · \(value.version)")
                        .foregroundStyle(AppPalette.success)
                default:
                    Button("Недоступен · повторить") {
                        Task { await load() }
                    }
                }
            }
        }
        .task { await load() }
    }

    private func load() async {
        environment = await apiClient.environment
        health = .loading
        do {
            health = .loaded(try await apiClient.get("/health", as: HealthDTO.self).data)
        } catch {
            health = LoadState(error: error)
        }
    }
}
#endif
