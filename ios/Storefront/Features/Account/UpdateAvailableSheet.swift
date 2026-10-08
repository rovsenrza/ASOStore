import SwiftUI

/// Shown once per launch when the server reports a newer storefront build for this device's
/// team (AuthController::resolveAppUpdate) — the app itself has no update mechanism of its own,
/// so this points people back to the site to re-download.
struct UpdateAvailableSheet: View {
    let update: AppUpdateDTO
    @Environment(\.apiClient) private var apiClient
    @Environment(\.openURL) private var openURL
    @Environment(\.dismiss) private var dismiss
    @State private var portalURL: URL?

    var body: some View {
        VStack(spacing: 20) {
            Image(systemName: "arrow.down.app.fill")
                .font(.system(size: 48))
                .foregroundStyle(AppPalette.accent)
                .padding(.top, 12)

            VStack(spacing: 6) {
                Text("Доступно обновление")
                    .font(.title2.weight(.bold))
                Text("Новая версия Ru App Store\(update.version.map { " \($0)" } ?? "") готова на сайте.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }

            VStack(spacing: 10) {
                Button {
                    if let portalURL {
                        openURL(portalURL.appending(path: "install.html"))
                    }
                    dismiss()
                } label: {
                    Text("Обновить")
                        .font(.headline)
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 50)
                        .background(AppPalette.accent, in: .rect(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(PressableStyle())
                .disabled(portalURL == nil)
                .accessibilityIdentifier("update-sheet-update")

                Button("Спасибо") { dismiss() }
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(.secondary)
                    .frame(minHeight: 44)
            }
        }
        .padding(.horizontal, 24)
        .padding(.bottom, 12)
        .presentationDetents([.medium])
        .task { portalURL = await apiClient.environment.portalURL }
    }
}

#Preview {
    Color.clear.sheet(isPresented: .constant(true)) {
        UpdateAvailableSheet(update: AppUpdateDTO(version: "1.6", buildNumber: 11))
            .environment(\.apiClient, APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock)))
    }
}
