import ImageIO
import UIKit

/// Decoded pictures kept in memory, so a row that scrolls back into view shows its icon at once
/// instead of flashing the placeholder. Safe to read from any thread.
nonisolated final class MemoryImageCache: @unchecked Sendable {
    static let shared = MemoryImageCache()

    private let cache = NSCache<NSString, UIImage>()

    private init() {
        // Roughly 150 icons or 25 banners; the system empties this itself under memory pressure.
        cache.totalCostLimit = 96 * 1024 * 1024
    }

    static func key(_ url: URL, maxPixel: Int) -> NSString {
        "\(url.absoluteString)|\(maxPixel)" as NSString
    }

    func image(for url: URL, maxPixel: Int) -> UIImage? {
        cache.object(forKey: Self.key(url, maxPixel: maxPixel))
    }

    func store(_ image: UIImage, for url: URL, maxPixel: Int) {
        let cost = Int(image.size.width * image.scale * image.size.height * image.scale * 4)
        cache.setObject(image, forKey: Self.key(url, maxPixel: maxPixel), cost: cost)
    }
}

/// Loads catalog pictures: one shared HTTP/2 session, a large on-disk cache that survives
/// launches, identical requests joined into one, and decoding at the size the screen needs
/// instead of the file's full size. Catalog files get a new name whenever they change, so a
/// cached copy never goes stale and is used without asking the server again.
actor ImagePipeline {
    static let shared = ImagePipeline()

    private let session: URLSession
    private var inFlight: [NSString: Task<UIImage?, Never>] = [:]

    init(session: URLSession? = nil) {
        if let session {
            self.session = session
        } else {
            let configuration = URLSessionConfiguration.default
            configuration.urlCache = URLCache(memoryCapacity: 24 * 1024 * 1024, diskCapacity: 300 * 1024 * 1024, directory: Self.cacheDirectory)
            configuration.requestCachePolicy = .returnCacheDataElseLoad
            configuration.timeoutIntervalForRequest = 20
            configuration.httpMaximumConnectionsPerHost = 6
            self.session = URLSession(configuration: configuration)
        }
    }

    private static var cacheDirectory: URL {
        URL.cachesDirectory.appending(path: "catalog-images", directoryHint: .isDirectory)
    }

    /// The picture at `url`, no larger than `maxPixel` on its long side. Nil when it cannot be loaded.
    func image(for url: URL, maxPixel: Int) async -> UIImage? {
        if let hit = MemoryImageCache.shared.image(for: url, maxPixel: maxPixel) {
            return hit
        }
        let key = MemoryImageCache.key(url, maxPixel: maxPixel)
        if let running = inFlight[key] {
            return await running.value
        }

        let session = session
        let task = Task.detached(priority: .userInitiated) { () -> UIImage? in
            guard let (data, response) = try? await session.data(from: url),
                  (response as? HTTPURLResponse)?.statusCode == 200,
                  let image = Self.downsample(data, maxPixel: maxPixel)
            else { return nil }
            MemoryImageCache.shared.store(image, for: url, maxPixel: maxPixel)
            return image
        }
        inFlight[key] = task
        let result = await task.value
        inFlight[key] = nil

        return result
    }

    /// Warms the caches for pictures about to be shown, without competing with what is on screen.
    func prefetch(_ urls: [URL], maxPixel: Int) {
        for url in urls where MemoryImageCache.shared.image(for: url, maxPixel: maxPixel) == nil {
            Task(priority: .utility) { _ = await self.image(for: url, maxPixel: maxPixel) }
        }
    }

    /// Decodes at thumbnail size, so a 1280 px banner costs one screen's worth of memory, not a full bitmap.
    nonisolated static func downsample(_ data: Data, maxPixel: Int) -> UIImage? {
        let sourceOptions = [kCGImageSourceShouldCache: false] as CFDictionary
        guard let source = CGImageSourceCreateWithData(data as CFData, sourceOptions) else { return nil }
        let options = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceShouldCacheImmediately: true,
            kCGImageSourceThumbnailMaxPixelSize: maxPixel,
        ] as CFDictionary
        guard let cgImage = CGImageSourceCreateThumbnailAtIndex(source, 0, options) else { return nil }

        return UIImage(cgImage: cgImage)
    }
}
