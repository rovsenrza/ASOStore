import SwiftUI

/// The app icon from the catalog, or placeholder artwork while it loads or
/// when the listing has none.
struct AppIconView: View {
    let app: StoreApp
    let size: Double

    var body: some View {
        Group {
            if let url = app.iconURL {
                AsyncImage(url: url, transaction: Transaction(animation: .easeOut(duration: 0.25))) { phase in
                    if let image = phase.image {
                        image.resizable().scaledToFill()
                            .transition(.opacity)
                    } else {
                        placeholder
                    }
                }
            } else {
                placeholder
            }
        }
        .frame(width: size, height: size)
        .clipShape(.rect(cornerRadius: size * 0.22, style: .continuous))
        .accessibilityHidden(true)
    }

    private var placeholder: some View {
        Image(systemName: app.systemImage)
            .font(.system(size: size * 0.38, weight: .medium))
            .foregroundStyle(.white)
            .frame(width: size, height: size)
            .background(app.artwork.color)
    }
}
