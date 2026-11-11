import SwiftUI

/// A remote screenshot or banner that fades in over a quiet placeholder.
struct ScreenshotImage: View {
    let url: URL?

    var body: some View {
        CachedImage(url: url, maxPixel: ImagePixels.screenshot) { image in
            image.resizable().scaledToFill()
        } placeholder: {
            AppPalette.elevated.shimmering()
        }
    }
}
