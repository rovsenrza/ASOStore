import Foundation
import CZlib

/// Raw DEFLATE chunks of delta-v2 packs (docs/adr/DELTA_V3.md, "Chunk"): every chunk comes from a
/// **fresh** zlib stream (`deflateInit2(level 6, Z_DEFLATED, windowBits -15, memLevel 8,
/// Z_DEFAULT_STRATEGY)`); the last chunk of an entry ends with `Z_FINISH` (final block), every other
/// one with `Z_FULL_FLUSH` (byte-aligned, not final). An entry's chunks, concatenated in order, are
/// one valid raw DEFLATE stream of the whole file, whoever compressed each chunk.
enum RawDeflate {
    static let level: Int32 = 6
    static let windowBits: Int32 = -15
    static let memLevel: Int32 = 8
    static let bufferSize = 1 << 20

    struct Failure: Error, CustomStringConvertible {
        var description: String
    }

    /// One zlib deflate stream. The `z_stream` lives on the heap: zlib keeps a pointer back to it
    /// and refuses a stream whose address moved between calls.
    final class Stream {
        private let stream: UnsafeMutablePointer<z_stream>

        init() throws {
            stream = .allocate(capacity: 1)
            stream.initialize(to: z_stream())
            let status = deflateInit2_(stream, RawDeflate.level, Z_DEFLATED, RawDeflate.windowBits, RawDeflate.memLevel,
                                       Z_DEFAULT_STRATEGY, zlibVersion(), Int32(MemoryLayout<z_stream>.size))
            guard status == Z_OK else {
                stream.deallocate()
                throw Failure(description: "deflateInit2 failed (\(status))")
            }
        }

        deinit {
            deflateEnd(stream)
            stream.deallocate()
        }

        /// Feeds `input` with `flush` (Z_NO_FLUSH, Z_FULL_FLUSH or Z_FINISH) and hands every
        /// compressed byte to `sink`. Returns the number of compressed bytes produced.
        @discardableResult
        func feed(_ input: UnsafeRawBufferPointer, flush: Int32, scratch: UnsafeMutableRawBufferPointer,
                  sink: (UnsafeRawBufferPointer) throws -> Void) throws -> UInt64 {
            stream.pointee.next_in = input.baseAddress.map { UnsafeMutablePointer(mutating: $0.assumingMemoryBound(to: Bytef.self)) }
            stream.pointee.avail_in = UInt32(input.count)
            var produced: UInt64 = 0
            while true {
                stream.pointee.next_out = scratch.baseAddress!.assumingMemoryBound(to: Bytef.self)
                stream.pointee.avail_out = UInt32(scratch.count)
                let status = deflate(stream, flush)
                guard status == Z_OK || status == Z_STREAM_END || status == Z_BUF_ERROR else {
                    throw Failure(description: "deflate failed (\(status))")
                }
                let count = scratch.count - Int(stream.pointee.avail_out)
                if count > 0 {
                    try sink(UnsafeRawBufferPointer(rebasing: scratch[0..<count]))
                    produced += UInt64(count)
                }
                if flush == Z_FINISH {
                    if status == Z_STREAM_END { break }
                } else if stream.pointee.avail_out != 0 {
                    break
                }
            }
            guard stream.pointee.avail_in == 0 else { throw Failure(description: "deflate left input unread") }
            return produced
        }
    }

    /// Compresses bytes `[start, end)` of `reader` as one chunk (`start < end`). `input` sees every
    /// uncompressed byte in order (checksums), `sink` every compressed byte. Returns the chunk length.
    /// `cancellation` is passed in: worker threads have no `Cancellation.current`.
    static func chunk(_ reader: FileReader, start: UInt64, end: UInt64, final: Bool,
                      input: UnsafeMutableRawBufferPointer, scratch: UnsafeMutableRawBufferPointer,
                      cancellation: Cancellation? = nil,
                      observe: (UnsafeRawBufferPointer) -> Void = { _ in },
                      sink: (UnsafeRawBufferPointer) throws -> Void) throws -> UInt64 {
        precondition(start < end)
        let stream = try Stream()
        var offset = start
        var produced: UInt64 = 0
        while offset < end {
            if cancellation?.isCancelled == true { throw DeltaPack.Unusable("cancelled") }
            let count = Int(min(UInt64(input.count), end - offset))
            let piece = UnsafeMutableRawBufferPointer(rebasing: input[0..<count])
            try reader.read(at: offset, into: piece)
            observe(UnsafeRawBufferPointer(piece))
            offset += UInt64(count)
            let flush = offset < end ? Z_NO_FLUSH : (final ? Z_FINISH : Z_FULL_FLUSH)
            produced += try stream.feed(UnsafeRawBufferPointer(piece), flush: flush, scratch: scratch, sink: sink)
        }
        return produced
    }

    /// In-memory form of `chunk`, for small inputs and tests.
    static func chunk(_ data: Data, final: Bool) throws -> Data {
        let stream = try Stream()
        let scratch = UnsafeMutableRawBufferPointer.allocate(byteCount: 64 << 10, alignment: 16)
        defer { scratch.deallocate() }
        var out = Data()
        try data.withUnsafeBytes { bytes in
            try stream.feed(bytes, flush: final ? Z_FINISH : Z_FULL_FLUSH, scratch: scratch) { out.append(contentsOf: $0) }
        }
        return out
    }
}

/// Variable ranges of a signed Mach-O (docs/adr/DELTA_V3.md, "Variable ranges"; mirrors
/// `variable_ranges` in research/delta-v3/proto.py): for each slice at offset `o`,
/// `[o, min(o + 4096, length))` and its `LC_CODE_SIGNATURE` data `[o + dataoff, o + dataoff + datasize)`,
/// sorted and merged when overlapping or adjacent. Thin files are `MH_MAGIC_64` little-endian; fat
/// files have the big-endian `FAT_MAGIC` header. Any other magic, a slice that is not `MH_MAGIC_64`
/// or has no code signature, and anything malformed (out of bounds, empty signature) give nil:
/// the file has no variable ranges and travels whole.
enum MachORanges {
    static let page: UInt64 = 4096
    static let thinMagic: [UInt8] = [0xCF, 0xFA, 0xED, 0xFE]
    static let fatMagic: [UInt8] = [0xCA, 0xFE, 0xBA, 0xBE]
    static let codeSignature: UInt32 = 0x1D
    static let maxSlices: UInt32 = 64
    static let maxCommandBytes: UInt32 = 16 << 20

    typealias Read = (_ offset: UInt64, _ count: Int) throws -> Data

    static func variableRanges(_ data: Data) -> [Range<UInt64>]? {
        variableRanges(size: UInt64(data.count)) { offset, count in
            let start = data.startIndex + Int(offset)
            return Data(data[start ..< start + count])
        }
    }

    static func variableRanges(_ reader: FileReader) -> [Range<UInt64>]? {
        variableRanges(size: reader.size) { try reader.read(at: $0, count: $1) }
    }

    static func variableRanges(size: UInt64, read: Read) -> [Range<UInt64>]? {
        guard size >= 8, let magic = try? read(0, 4) else { return nil }
        var bases: [UInt64]
        if Array(magic) == fatMagic {
            guard let header = try? read(4, 4) else { return nil }
            let count = header.be32(0)
            guard count > 0, count <= maxSlices, 8 + 20 * UInt64(count) <= size,
                  let table = try? read(8, 20 * Int(count)) else { return nil }
            bases = (0..<Int(count)).map { UInt64(table.be32(20 * $0 + 8)) }
        } else if Array(magic) == thinMagic {
            bases = [0]
        } else {
            return nil
        }

        var ranges: [Range<UInt64>] = []
        for base in bases {
            guard base < size else { return nil }
            ranges.append(base ..< min(base + page, size))
            guard let signature = signatureRange(base: base, size: size, read: read) else { return nil }
            ranges.append(signature)
        }
        ranges.sort { ($0.lowerBound, $0.upperBound) < ($1.lowerBound, $1.upperBound) }
        var merged: [Range<UInt64>] = []
        for range in ranges {
            if let last = merged.last, range.lowerBound <= last.upperBound {
                merged[merged.count - 1] = last.lowerBound ..< max(last.upperBound, range.upperBound)
            } else {
                merged.append(range)
            }
        }
        return merged
    }

    /// The slice's `LC_CODE_SIGNATURE` data range, in file offsets.
    private static func signatureRange(base: UInt64, size: UInt64, read: Read) -> Range<UInt64>? {
        guard base + 32 <= size, let header = try? read(base, 32), Array(header.prefix(4)) == thinMagic else { return nil }
        let commands = header.le32(16), commandBytes = header.le32(20)
        guard commandBytes <= maxCommandBytes, base + 32 + UInt64(commandBytes) <= size,
              let table = try? read(base + 32, Int(commandBytes)) else { return nil }
        var offset = 0
        for _ in 0..<commands {
            guard offset + 8 <= table.count else { return nil }
            let command = table.le32(offset), length = Int(table.le32(offset + 4))
            if command == codeSignature {
                guard offset + 16 <= table.count else { return nil }
                let dataOffset = UInt64(table.le32(offset + 8)), dataSize = UInt64(table.le32(offset + 12))
                let start = base + dataOffset, end = start + dataSize
                guard dataSize > 0, end <= size else { return nil }
                return start ..< end
            }
            guard length >= 8 else { return nil }
            offset += length
        }
        return nil
    }

    /// One part of a file: a variable range (`team`) or a gap around one (`core`).
    struct Segment: Equatable {
        var team: Bool
        var range: Range<UInt64>
    }

    /// The file's segments in order, covering `[0, length)` exactly, none empty (proto.py `segments`).
    static func segments(length: UInt64, variable: [Range<UInt64>]) -> [Segment] {
        var out: [Segment] = []
        var cursor: UInt64 = 0
        for range in variable {
            if range.lowerBound > cursor { out.append(Segment(team: false, range: cursor ..< range.lowerBound)) }
            out.append(Segment(team: true, range: range))
            cursor = range.upperBound
        }
        if cursor < length { out.append(Segment(team: false, range: cursor ..< length)) }
        return out
    }
}

extension Data {
    func be32(_ offset: Int) -> UInt32 {
        let start = startIndex + offset
        return UInt32(self[start]) << 24 | UInt32(self[start + 1]) << 16 | UInt32(self[start + 2]) << 8 | UInt32(self[start + 3])
    }
}
