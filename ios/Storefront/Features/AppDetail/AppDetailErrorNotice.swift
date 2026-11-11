import SwiftUI

/// The detail request failed: says so in one line and offers to try again.
struct AppDetailErrorNotice: View {
    let error: APIError
    /// Nothing about the app is known yet (opened from a link), not just its description.
    var appIsUnknown = false
    let retry: () -> Void

    private var isOffline: Bool {
        error.code == .offline || error.code == .networkError
    }

    var body: some View {
        // One line when it fits; the button drops under the message at large text sizes.
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 12) {
                message
                Spacer(minLength: 0)
                retryButton
            }
            VStack(alignment: .leading, spacing: 8) {
                message
                retryButton
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(AppPalette.elevated, in: .rect(cornerRadius: 12, style: .continuous))
        .accessibilityIdentifier("detail-error")
    }

    private var message: some View {
        Label(isOffline ? "Нет подключения к интернету" : (appIsUnknown ? "Не удалось загрузить приложение" : "Не удалось загрузить описание"), systemImage: isOffline ? "wifi.slash" : "exclamationmark.triangle")
            .font(.footnote.weight(.medium))
            .foregroundStyle(.secondary)
    }

    private var retryButton: some View {
        Button("Повторить", action: retry)
            .font(.footnote.bold())
    }
}
