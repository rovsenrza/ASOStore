import SwiftUI

/// Shown above cached content while the network is unavailable.
struct StaleDataBanner: View {
    var body: some View {
        Label("Нет подключения — показаны сохранённые данные", systemImage: "wifi.slash")
            .font(.footnote.weight(.medium))
            .foregroundStyle(.secondary)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            .background(AppPalette.elevated)
            .clipShape(.rect(cornerRadius: 12))
            .accessibilityIdentifier("stale-banner")
    }
}
