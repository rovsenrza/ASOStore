import SwiftUI

// The authorized test app for the install pipeline (FULL_PLAN §18.4, IMPLEMENTATION_PLAN P5-OPS-01).
// It does nothing except prove that it was signed for this device and launches.
@main
struct DemoApp: App {
    var body: some Scene {
        WindowGroup {
            VStack(spacing: 16) {
                Image(systemName: "checkmark.seal.fill")
                    .font(.system(size: 64))
                    .foregroundStyle(.green)
                    .accessibilityHidden(true)
                Text("Demo App")
                    .font(.largeTitle.bold())
                Text("Установлено через Storefront. Если вы видите этот экран, подпись и установка работают.")
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                Text(Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "")
                    .font(.footnote.monospaced())
            }
            .padding(32)
        }
    }
}
