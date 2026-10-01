import SwiftUI

/// A catalog picture loaded through `ImagePipeline`: from memory at once when it was seen
/// recently, otherwise fetched (or read from the disk cache) and faded in over the placeholder.
struct CachedImage<Content: View, Placeholder: View>: View {
    let url: URL?
    /// Longest side to decode at, in pixels. Use `ImagePixels.icon(_:scale:)` for icons.
    let maxPixel: Int
    @ViewBuilder let content: (Image) -> Content
    @ViewBuilder let placeholder: () -> Placeholder

    @State private var image: UIImage?

    init(
        url: URL?,
        maxPixel: Int,
        @ViewBuilder content: @escaping (Image) -> Content,
        @ViewBuilder placeholder: @escaping () -> Placeholder
    ) {
        self.url = url
        self.maxPixel = maxPixel
        self.content = content
        self.placeholder = placeholder
        // A picture already in memory is there on the first frame: no placeholder flash while scrolling.
        _image = State(initialValue: url.flatMap { MemoryImageCache.shared.image(for: $0, maxPixel: maxPixel) })
    }

    var body: some View {
        ZStack {
            if let image {
                content(Image(uiImage: image))
                    .transition(.opacity)
            } else {
                placeholder()
            }
        }
        .animation(.easeOut(duration: 0.25), value: image != nil)
        .task(id: url) { await load() }
    }

    private func load() async {
        guard let url else {
            image = nil
            return
        }
        if let hit = MemoryImageCache.shared.image(for: url, maxPixel: maxPixel) {
            image = hit
            return
        }
        image = await ImagePipeline.shared.image(for: url, maxPixel: maxPixel)
    }
}

/// Decode sizes. Icons snap to a few buckets so one icon shown at several sizes is decoded
/// only a few times; banners and screenshots use fixed ceilings (the server stores banners at 1280 px).
enum ImagePixels {
    static let banner = 1280
    static let screenshot = 1600

    static func icon(_ points: Double, scale: Double) -> Int {
        let needed = Int((points * scale).rounded(.up))

        return [128, 192, 256, 384].first { $0 >= needed } ?? 384
    }
}
