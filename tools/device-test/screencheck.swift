// Reads one device screenshot and prints {"uniform": share, "text": "..."} as JSON:
// `uniform` is the share of pixels near the most common colour (a blank or frozen
// black/white screen is close to 1), `text` what the Vision framework reads on it
// (Russian and English), so the agent can spot alerts such as jailbreak warnings.
//
//   swiftc -O screencheck.swift -o screencheck && ./screencheck shot.png

import CoreGraphics
import Foundation
import ImageIO
import Vision

guard CommandLine.arguments.count == 2,
      let source = CGImageSourceCreateWithURL(URL(fileURLWithPath: CommandLine.arguments[1]) as CFURL, nil),
      let image = CGImageSourceCreateImageAtIndex(source, 0, nil)
else {
    FileHandle.standardError.write("usage: screencheck IMAGE\n".data(using: .utf8)!)
    exit(2)
}

// Colours bucketed to 4 bits per channel on a small copy of the screen.
let width = 90, height = 195
var pixels = [UInt8](repeating: 0, count: width * height * 4)
let context = CGContext(data: &pixels, width: width, height: height, bitsPerComponent: 8, bytesPerRow: width * 4,
                        space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
context.draw(image, in: CGRect(x: 0, y: 0, width: width, height: height))
var buckets = [Int: Int]()
for index in stride(from: 0, to: pixels.count, by: 4) {
    let key = (Int(pixels[index]) >> 4) << 8 | (Int(pixels[index + 1]) >> 4) << 4 | (Int(pixels[index + 2]) >> 4)
    buckets[key, default: 0] += 1
}
let uniform = Double(buckets.values.max() ?? 0) / Double(width * height)

let request = VNRecognizeTextRequest()
request.recognitionLevel = .accurate
request.recognitionLanguages = ["ru-RU", "en-US"]
request.usesLanguageCorrection = true
try? VNImageRequestHandler(cgImage: image).perform([request])
let text = (request.results ?? []).compactMap { $0.topCandidates(1).first?.string }.joined(separator: "\n")

let output = try JSONSerialization.data(withJSONObject: ["uniform": (uniform * 1000).rounded() / 1000, "text": text])
print(String(data: output, encoding: .utf8)!)
