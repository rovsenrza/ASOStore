import SwiftUI

/// The app icon from the catalog, or placeholder artwork while it loads or
/// when the listing has none.
struct AppIconView: View {
    let app: StoreApp
    let size: Double
    @Environment(\.displayScale) private var displayScale

    var body: some View {
        Group {
            if let url = app.iconURL {
                CachedImage(url: url, maxPixel: ImagePixels.icon(size, scale: displayScale)) { image in
                    image.resizable().scaledToFill()
                } placeholder: {
                    placeholder
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
