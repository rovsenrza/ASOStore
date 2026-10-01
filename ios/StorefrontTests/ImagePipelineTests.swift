import Foundation
import Testing
import UIKit
@testable import Storefront

/// Answers every image request from memory and counts them, so the tests never touch the network.
nonisolated final class ImageStubProtocol: URLProtocol, @unchecked Sendable {
    nonisolated(unsafe) static var hits = 0
    nonisolated(unsafe) static var status = 200
    nonisolated(unsafe) static var body = Data()
    private static let lock = NSLock()

    static func reset(status: Int = 200, body: Data) {
        lock.withLock {
            hits = 0
            self.status = status
            self.body = body
        }
    }

    static var requestCount: Int { lock.withLock { hits } }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        let (status, body) = Self.lock.withLock { () -> (Int, Data) in
            Self.hits += 1
            return (Self.status, Self.body)
        }
        let response = HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: "HTTP/2", headerFields: ["Content-Type": "image/png"])!
        // A short pause lets concurrent callers overlap, which is what the de-duplication has to survive.
        DispatchQueue.global().asyncAfter(deadline: .now() + 0.05) {
            self.client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            self.client?.urlProtocol(self, didLoad: body)
            self.client?.urlProtocolDidFinishLoading(self)
        }
    }

    override func stopLoading() {}
}

@Suite("ImagePipeline", .serialized)
struct ImagePipelineTests {
    private func png(side: Int) -> Data {
        let format = UIGraphicsImageRendererFormat.preferred()
        format.scale = 1
        let renderer = UIGraphicsImageRenderer(size: CGSize(width: side, height: side), format: format)
        return renderer.pngData { context in
            UIColor.systemBlue.setFill()
            context.fill(CGRect(x: 0, y: 0, width: side, height: side))
        }
    }

    private func pipeline() -> ImagePipeline {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ImageStubProtocol.self]
        return ImagePipeline(session: URLSession(configuration: configuration))
    }

    @Test func decodesAtTheRequestedSizeNotTheFileSize() async throws {
        ImageStubProtocol.reset(body: png(side: 1024))
        let url = try #require(URL(string: "https://img.test/size-\(UUID().uuidString).png"))

        let image = try #require(await pipeline().image(for: url, maxPixel: 256))

        #expect(Int(image.size.width * image.scale) == 256)
        #expect(Int(image.size.height * image.scale) == 256)
    }

    @Test func joinsSimultaneousRequestsForTheSameFile() async throws {
        ImageStubProtocol.reset(body: png(side: 300))
        let url = try #require(URL(string: "https://img.test/join-\(UUID().uuidString).png"))
        let pipeline = pipeline()

        var loaded = 0
        await withTaskGroup(of: Bool.self) { group in
            for _ in 0..<8 { group.addTask { await pipeline.image(for: url, maxPixel: 192) != nil } }
            for await ok in group where ok { loaded += 1 }
        }

        #expect(loaded == 8)
        #expect(ImageStubProtocol.requestCount == 1)
    }

    @Test func answersFromMemoryTheSecondTime() async throws {
        ImageStubProtocol.reset(body: png(side: 300))
        let url = try #require(URL(string: "https://img.test/memory-\(UUID().uuidString).png"))
        let pipeline = pipeline()

        _ = await pipeline.image(for: url, maxPixel: 192)
        let again = MemoryImageCache.shared.image(for: url, maxPixel: 192)
        _ = await pipeline.image(for: url, maxPixel: 192)

        #expect(again != nil)
        #expect(ImageStubProtocol.requestCount == 1)
    }

    @Test func keepsSeparateCopiesForSeparateSizes() async throws {
        ImageStubProtocol.reset(body: png(side: 600))
        let url = try #require(URL(string: "https://img.test/sizes-\(UUID().uuidString).png"))
        let pipeline = pipeline()

        let small = try #require(await pipeline.image(for: url, maxPixel: 128))
        let large = try #require(await pipeline.image(for: url, maxPixel: 384))

        #expect(Int(small.size.width) == 128)
        #expect(Int(large.size.width) == 384)
    }

    @Test func returnsNilForAnErrorAndForGarbage() async throws {
        let missing = try #require(URL(string: "https://img.test/missing-\(UUID().uuidString).png"))
        ImageStubProtocol.reset(status: 404, body: Data())
        #expect(await pipeline().image(for: missing, maxPixel: 128) == nil)

        let garbage = try #require(URL(string: "https://img.test/garbage-\(UUID().uuidString).png"))
        ImageStubProtocol.reset(body: Data("not an image".utf8))
        #expect(await pipeline().image(for: garbage, maxPixel: 128) == nil)
    }

    @Test func snapsIconSizesToBuckets() {
        #expect(ImagePixels.icon(30, scale: 3) == 128)
        #expect(ImagePixels.icon(50, scale: 3) == 192)
        #expect(ImagePixels.icon(76, scale: 3) == 256)
        #expect(ImagePixels.icon(108, scale: 3) == 384)
        #expect(ImagePixels.icon(200, scale: 3) == 384)
    }
}
