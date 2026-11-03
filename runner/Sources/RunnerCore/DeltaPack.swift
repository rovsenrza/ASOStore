import Foundation

/// Delta builds (docs/adr/DELTA_BUILDS.md, pack format `delta-v1`): instead of uploading the whole
/// re-signed IPA, the runner uploads only the entries that differ from the source IPA (the Mach-O
/// binaries, the profile, Info.plist, the seal) plus a manifest naming where every entry of the
/// final IPA comes from. The backend composes the delivered IPA from the artifact's original and
/// the pack. Entries are copied raw (never recompressed) with bounded memory; any doubt, any
/// parsing problem and any archive this format does not cover means "no delta" (the caller then
/// uploads the full IPA as before): a delta attempt never fails a job.
///
/// Foundation only, so it builds on Linux as well as on macOS.
public enum DeltaPack {
    public static let format = "delta-v1"
    /// The manifest, the last entry of a pack. Under `Payload/` so the ordinary IPA inspector
    /// accepts a pack as it is; never part of the final IPA.
    public static let manifestName = "Payload/.delta-manifest.json"

    /// Entries with these endings (inside `Payload/`, at any depth) always travel in the pack, so
    /// a pack can be inspected on its own.
    static let mandatorySuffixes = ["/Info.plist", "/embedded.mobileprovision", "/_CodeSignature/CodeResources"]
    /// A pack is an ordinary zip: no zip64, so these field maxima are markers, not values.
    static let maxEntries = 0xFFFF
    static let maxArchiveBytes: UInt64 = 0xFFFF_FFFF
    static let copyChunk = 1 << 20

    public struct Result: Sendable {
        /// SHA-256 of the pack file (what is uploaded and reported).
        public var sha256: String
        public var baseSHA256: String
        public var packBytes: Int64
        public var signedBytes: Int64
        /// Entries of the final IPA.
        public var entriesTotal: Int
        /// Entries copied from the signed IPA into the pack (the manifest is not counted).
        public var entriesPack: Int
        public var seconds: Double

        /// Keys for the job report.
        public var report: [String: String] {
            [
                "pack_bytes": String(packBytes),
                "entries_total": String(entriesTotal),
                "entries_pack": String(entriesPack),
                "delta_seconds": String(format: "%.1f", seconds),
            ]
        }
    }

    public enum Outcome: Sendable {
        case pack(Result)
        /// Why no delta is used; one short line for the log.
        case fallback(String)

        public var result: Result? {
            if case .pack(let result) = self { return result }
            return nil
        }

        public var reason: String? {
            if case .fallback(let reason) = self { return reason }
            return nil
        }
    }

    /// Builds the pack for `signed` against `source` at `output`. Never throws: when delta cannot
    /// be used (or anything goes wrong) the answer is `.fallback(reason)` and `output` is removed.
    /// `baseSHA256` is the SHA-256 of the source IPA, written into the manifest.
    public static func build(source: URL, signed: URL, output: URL, baseSHA256: String) -> Outcome {
        let started = Date()
        do {
            let result = try write(source: source, signed: signed, output: output, baseSHA256: baseSHA256.lowercased(), started: started)
            return .pack(result)
        } catch let error as Unusable {
            try? FileManager.default.removeItem(at: output)
            return .fallback(error.reason)
        } catch {
            try? FileManager.default.removeItem(at: output)
            return .fallback("I/O error: \(error)")
        }
    }

    struct Unusable: Error {
        var reason: String
        init(_ reason: String) { self.reason = reason }
    }

    // MARK: Classification

    /// The record pair the final IPA's base entries are written with: `version made by` and
    /// external attributes of the signed archive's first non-directory entry. zsign writes the
    /// same pair (zeros) for every entry, while the source carries its own (Unix modes).
    static func recordPair(_ signed: Archive) throws -> RecordPair {
        guard let entry = signed.entries.first(where: { $0.name.last != UInt8(ascii: "/") }) else {
            throw Unusable("signed IPA has no entry that is not a directory")
        }
        return RecordPair(madeBy: entry.madeBy, external: entry.externalAttributes)
    }

    struct RecordPair: Equatable {
        var madeBy: UInt16
        var external: UInt32
    }

    /// For each entry of the signed archive (in its central directory order): does it come from
    /// the base IPA? Throws `Unusable` for archives delta cannot describe (duplicate names, ...).
    static func classify(source: Archive, signed: Archive, pair: RecordPair) throws -> [Bool] {
        var baseIndex: [Data: Int] = [:]
        for (index, entry) in source.entries.enumerated() {
            guard baseIndex.updateValue(index, forKey: entry.name) == nil else { throw Unusable("source IPA has duplicate entry names") }
        }
        var seen = Set<Data>()
        for entry in signed.entries {
            guard seen.insert(entry.name).inserted else { throw Unusable("signed IPA has duplicate entry names") }
        }
        let manifest = Data(manifestName.utf8)
        if baseIndex[manifest] != nil || seen.contains(manifest) { throw Unusable("an IPA already has an entry named \(manifestName)") }

        return signed.entries.map { entry in
            guard let index = baseIndex[entry.name] else { return false }
            let original = source.entries[index]
            return original.crc == entry.crc
                && original.uncompressedSize == entry.uncompressedSize
                && RecordPair(madeBy: entry.madeBy, external: entry.externalAttributes) == pair
                && baseUsable(original, name: entry.name)
        }
    }

    /// Rules 3 to 5: whatever the source entry could be taken as is, whatever its content.
    static func baseUsable(_ original: Archive.Entry, name: Data) -> Bool {
        isASCII(name)
            && (original.method == 0 || original.method == 8)
            && original.flags & 1 == 0
            && !original.hasZip64Extra
            && !isMandatory(name)
    }

    static func isASCII(_ name: Data) -> Bool {
        !name.contains(where: { $0 >= 0x80 })
    }

    static func isMandatory(_ name: Data) -> Bool {
        guard name.starts(with: Data("Payload/".utf8)) else { return false }
        return mandatorySuffixes.contains { suffix in
            let bytes = Data(suffix.utf8)
            return name.count > bytes.count && name.suffix(bytes.count) == bytes
        }
    }

    // MARK: Writing

    private static func write(source: URL, signed: URL, output: URL, baseSHA256: String, started: Date) throws -> Result {
        guard baseSHA256.count == 64, baseSHA256.allSatisfy({ $0.isHexDigit }) else { throw Unusable("base SHA-256 is not a hex digest") }
        let base = try Archive.read(source, label: "source")
        let new = try Archive.read(signed, label: "signed")
        guard !new.entries.contains(where: { $0.hasZip64Extra }) else { throw Unusable("signed IPA has a zip64 extra field") }
        let pair = try recordPair(new)
        let fromBase = try classify(source: base, signed: new, pair: pair)

        let manifest = try manifestJSON(baseSHA256: baseSHA256, pair: pair, names: new.entries.map(\.name), fromBase: fromBase)
        let packIndexes = fromBase.indices.filter { !fromBase[$0] }
        guard packIndexes.count + 1 < maxEntries else { throw Unusable("pack would need more than 65534 entries") }

        // The pack's size is known before a byte is written: a pack that is not worth it costs nothing.
        let manifestName = Data(Self.manifestName.utf8)
        var expected: UInt64 = UInt64(30 + manifestName.count + manifest.count) + 22
        for index in packIndexes {
            let entry = new.entries[index]
            expected += entry.extentEnd - UInt64(entry.localOffset) + UInt64(entry.record.count)
        }
        expected += UInt64(46 + manifestName.count)
        guard expected < maxArchiveBytes else { throw Unusable("pack would be 4 GiB or more") }
        guard expected * 2 < new.size else { throw Unusable("pack of \(expected) bytes is not under half of the \(new.size) byte signed IPA") }

        let reader = try FileReader(signed)
        let sink = try FileSink(output)
        var directory = Data()
        var written: UInt64 = 0
        do {
            let buffer = UnsafeMutableRawBufferPointer.allocate(byteCount: copyChunk, alignment: 16)
            defer { buffer.deallocate() }
            for index in packIndexes {
                let entry = new.entries[index]
                // The local header must be the one the central directory describes.
                let header = try reader.read(at: UInt64(entry.localOffset), count: 30 + entry.name.count)
                guard header.le32(0) == 0x0403_4B50, Int(header.le16(26)) == entry.name.count, header.suffix(entry.name.count) == entry.name else {
                    throw Unusable("signed IPA has a broken local header for entry \(index)")
                }
                var record = entry.record
                record.setLE32(UInt32(written), at: 42)
                directory.append(record)
                var offset = UInt64(entry.localOffset)
                while offset < entry.extentEnd {
                    let count = Int(min(UInt64(copyChunk), entry.extentEnd - offset))
                    try reader.read(at: offset, into: UnsafeMutableRawBufferPointer(rebasing: buffer[0..<count]))
                    try sink.write(UnsafeRawBufferPointer(rebasing: buffer[0..<count]))
                    offset += UInt64(count)
                    if Cancellation.current?.isCancelled == true { throw Unusable("cancelled") }
                }
                written += entry.extentEnd - UInt64(entry.localOffset)
            }

            try finish(sink, directory: &directory, written: &written, manifest: manifest, packCount: packIndexes.count)
        } catch {
            sink.close()
            throw error
        }
        let sha256 = sink.finish()
        guard written == expected, sink.size == expected else { throw Unusable("pack came out \(sink.size) bytes, expected \(expected)") }

        // The pack must read back as the archive it is meant to be.
        let check = try Archive.read(output, label: "pack")
        guard check.entries.count == packIndexes.count + 1, check.entries.last?.name == manifestName,
              zip(check.entries, packIndexes).allSatisfy({ $0.name == new.entries[$1].name }) else {
            throw Unusable("pack does not read back as expected")
        }

        return Result(sha256: sha256, baseSHA256: baseSHA256, packBytes: Int64(written), signedBytes: Int64(new.size),
                      entriesTotal: new.entries.count, entriesPack: packIndexes.count, seconds: Date().timeIntervalSince(started))
    }

    /// The end of every pack: the stored manifest entry, then the central directory (the records of
    /// the pack entries already in `directory`, plus the manifest's) and the end record.
    static func finish(_ sink: FileSink, directory: inout Data, written: inout UInt64, manifest: Data, packCount: Int) throws {
        let manifestName = Data(Self.manifestName.utf8)
        var local = Data()
        local.append32(0x0403_4B50)
        local.append16(20); local.append16(0); local.append16(0)         // version, flags, method (stored)
        local.append16(0); local.append16(Self.dosDate)
        local.append32(crc32(manifest))
        local.append32(UInt32(manifest.count)); local.append32(UInt32(manifest.count))
        local.append16(UInt16(manifestName.count)); local.append16(0)
        local.append(manifestName)
        local.append(manifest)
        try sink.write(local)

        var record = Data()
        record.append32(0x0201_4B50)
        record.append16(3 << 8 | 20); record.append16(20)               // made on Unix, needs 2.0
        record.append16(0); record.append16(0)                          // flags, method
        record.append16(0); record.append16(Self.dosDate)
        record.append32(crc32(manifest))
        record.append32(UInt32(manifest.count)); record.append32(UInt32(manifest.count))
        record.append16(UInt16(manifestName.count))
        for _ in 0..<4 { record.append16(0) }                           // extra, comment, disk, internal attributes
        record.append32(0o100644 << 16)
        record.append32(UInt32(written))
        record.append(manifestName)
        directory.append(record)
        written += UInt64(local.count)

        var end = Data()
        end.append32(0x0605_4B50)
        end.append16(0); end.append16(0)
        end.append16(UInt16(packCount + 1)); end.append16(UInt16(packCount + 1))
        end.append32(UInt32(directory.count)); end.append32(UInt32(written))
        end.append16(0)
        try sink.write(directory)
        try sink.write(end)
        written += UInt64(directory.count + end.count)
    }

    /// 1980-01-01, so equal inputs give equal packs.
    static let dosDate: UInt16 = 0x0021

    /// `{"version":1,"base_sha256":"…","made_by":0,"external":0,"entries":[["name",1],…]}`, compact,
    /// in the signed IPA's order.
    static func manifestJSON(baseSHA256: String, pair: RecordPair, names: [Data], fromBase: [Bool]) throws -> Data {
        var json = Data("{\"version\":1,\"base_sha256\":\"\(baseSHA256)\",\"made_by\":\(pair.madeBy),\"external\":\(pair.external),\"entries\":[".utf8)
        for (index, entry) in names.enumerated() {
            guard let name = String(data: entry, encoding: .utf8) else { throw Unusable("entry \(index) has a name that is not valid UTF-8") }
            if index > 0 { json.append(UInt8(ascii: ",")) }
            json.append(Data("[\"\(escape(name))\",\(fromBase[index] ? 0 : 1)]".utf8))
        }
        json.append(Data("]}".utf8))
        return json
    }

    static func escape(_ text: String) -> String {
        var result = ""
        for scalar in text.unicodeScalars {
            switch scalar {
            case "\"": result += "\\\""
            case "\\": result += "\\\\"
            case "\n": result += "\\n"
            case "\r": result += "\\r"
            case "\t": result += "\\t"
            default:
                if scalar.value < 0x20 || scalar.value == 0x7F {
                    result += String(format: "\\u%04x", scalar.value)
                } else {
                    result.unicodeScalars.append(scalar)
                }
            }
        }
        return result
    }

    // MARK: CRC-32

    /// Slicing-by-8 tables: eight table lookups per 8 bytes (a 2.7 GB tree is checksummed per build).
    private static let crcTables: [UInt32] = {
        var tables = [UInt32](repeating: 0, count: 8 * 256)
        for index in 0..<256 {
            var value = UInt32(index)
            for _ in 0..<8 { value = value & 1 == 1 ? 0xEDB8_8320 ^ (value >> 1) : value >> 1 }
            tables[index] = value
        }
        for index in 0..<256 {
            var value = tables[index]
            for slice in 1..<8 {
                value = tables[Int(value & 0xFF)] ^ (value >> 8)
                tables[slice * 256 + index] = value
            }
        }
        return tables
    }()

    /// CRC-32 (zip, IEEE 802.3). Pass the previous result as `crc` to continue over more data.
    public static func crc32(_ data: Data, crc: UInt32 = 0) -> UInt32 {
        data.withUnsafeBytes { crc32($0, crc: crc) }
    }

    public static func crc32(_ bytes: UnsafeRawBufferPointer, crc: UInt32 = 0) -> UInt32 {
        var value = ~crc
        guard var pointer = bytes.baseAddress else { return crc }
        var remaining = bytes.count
        crcTables.withUnsafeBufferPointer { table in
            while remaining >= 8 {
                let low = UInt32(littleEndian: pointer.loadUnaligned(as: UInt32.self)) ^ value
                let high = UInt32(littleEndian: pointer.loadUnaligned(fromByteOffset: 4, as: UInt32.self))
                value = table[7 * 256 + Int(low & 0xFF)] ^ table[6 * 256 + Int(low >> 8 & 0xFF)]
                    ^ table[5 * 256 + Int(low >> 16 & 0xFF)] ^ table[4 * 256 + Int(low >> 24)]
                    ^ table[3 * 256 + Int(high & 0xFF)] ^ table[2 * 256 + Int(high >> 8 & 0xFF)]
                    ^ table[256 + Int(high >> 16 & 0xFF)] ^ table[Int(high >> 24)]
                pointer += 8
                remaining -= 8
            }
            while remaining > 0 {
                value = table[Int((value ^ UInt32(pointer.load(as: UInt8.self))) & 0xFF)] ^ (value >> 8)
                pointer += 1
                remaining -= 1
            }
        }
        return ~value
    }

    // MARK: Reading a zip

    /// The central directory of a zip, without zip64.
    struct Archive {
        struct Entry {
            var name: Data
            var flags: UInt16
            var method: UInt16
            var crc: UInt32
            var compressedSize: UInt32
            var uncompressedSize: UInt32
            var externalAttributes: UInt32
            /// The 16-bit "version made by" field of the central record.
            var madeBy: UInt16 { record.le16(4) }
            /// A zip64 extra field (or an extra field too broken to tell).
            var hasZip64Extra: Bool
            var localOffset: UInt32
            /// End of the raw extent `[localOffset, extentEnd)`: the next larger local header
            /// offset, or the start of the central directory (data descriptors and padding
            /// travel with the entry).
            var extentEnd: UInt64
            /// The central directory record as it is in the file.
            var record: Data
        }

        var entries: [Entry]
        var size: UInt64
        var directoryOffset: UInt64

        static func read(_ url: URL, label: String) throws -> Archive {
            let reader = try FileReader(url)
            let size = reader.size
            guard size >= 22 else { throw Unusable("\(label) IPA is too small to be a zip") }
            guard size <= maxArchiveBytes else { throw Unusable("\(label) IPA is 4 GiB or more") }

            let tailLength = min(size, 65_557) // end-of-central-directory record + longest comment
            let tail = try reader.read(at: size - tailLength, count: Int(tailLength))
            var end: Int?
            for index in stride(from: tail.count - 22, through: 0, by: -1)
                where tail.le32(index) == 0x0605_4B50 && index + 22 + Int(tail.le16(index + 20)) == tail.count {
                end = index
                break
            }
            guard let end else { throw Unusable("\(label) IPA has no end of central directory") }
            if end >= 20, tail.le32(end - 20) == 0x0706_4B50 { throw Unusable("\(label) IPA is zip64") }
            let disks = tail.le16(end + 4), directoryDisk = tail.le16(end + 6)
            let entryCount = Int(tail.le16(end + 10))
            let directorySize = UInt64(tail.le32(end + 12)), directoryOffset = UInt64(tail.le32(end + 16))
            if entryCount == maxEntries || directorySize == maxArchiveBytes || directoryOffset == maxArchiveBytes {
                throw Unusable("\(label) IPA needs zip64 (or has 65535 entries or more)")
            }
            guard disks == 0, directoryDisk == 0, tail.le16(end + 8) == tail.le16(end + 10) else { throw Unusable("\(label) IPA is split over several disks") }
            guard entryCount > 0 else { throw Unusable("\(label) IPA has no entries") }
            let endOffset = size - tailLength + UInt64(end)
            guard directoryOffset + directorySize <= endOffset, directorySize <= 256 << 20 else {
                throw Unusable("\(label) IPA has a broken central directory")
            }

            let directory = try reader.read(at: directoryOffset, count: Int(directorySize))
            var entries: [Entry] = []
            entries.reserveCapacity(entryCount)
            var position = 0
            for _ in 0..<entryCount {
                guard position + 46 <= directory.count, directory.le32(position) == 0x0201_4B50 else {
                    throw Unusable("\(label) IPA has a broken central directory")
                }
                let nameLength = Int(directory.le16(position + 28))
                let extraLength = Int(directory.le16(position + 30))
                let next = position + 46 + nameLength + extraLength + Int(directory.le16(position + 32))
                guard next <= directory.count else { throw Unusable("\(label) IPA has a broken central directory") }
                let compressed = directory.le32(position + 20), uncompressed = directory.le32(position + 24)
                let localOffset = directory.le32(position + 42)
                if compressed == 0xFFFF_FFFF || uncompressed == 0xFFFF_FFFF || localOffset == 0xFFFF_FFFF {
                    throw Unusable("\(label) IPA needs zip64")
                }
                guard directory.le16(position + 34) == 0, UInt64(localOffset) < directoryOffset else {
                    throw Unusable("\(label) IPA has a broken central directory")
                }
                let nameStart = position + 46
                entries.append(Entry(
                    name: Data(directory[directory.startIndex + nameStart ..< directory.startIndex + nameStart + nameLength]),
                    flags: directory.le16(position + 8),
                    method: directory.le16(position + 10),
                    crc: directory.le32(position + 16),
                    compressedSize: compressed,
                    uncompressedSize: uncompressed,
                    externalAttributes: directory.le32(position + 38),
                    hasZip64Extra: hasZip64Extra(Data(directory[directory.startIndex + nameStart + nameLength ..< directory.startIndex + nameStart + nameLength + extraLength])),
                    localOffset: localOffset,
                    extentEnd: 0,
                    record: Data(directory[directory.startIndex + position ..< directory.startIndex + next])
                ))
                position = next
            }
            guard position == directory.count else { throw Unusable("\(label) IPA has data after its central directory records") }

            // Raw extents: from each local header to the next larger offset.
            let order = entries.indices.sorted { entries[$0].localOffset < entries[$1].localOffset }
            for (rank, index) in order.enumerated() {
                let next = rank + 1 < order.count ? UInt64(entries[order[rank + 1]].localOffset) : directoryOffset
                let start = UInt64(entries[index].localOffset)
                guard next > start, next - start >= UInt64(30 + entries[index].name.count) else {
                    throw Unusable("\(label) IPA has overlapping local headers")
                }
                entries[index].extentEnd = next
            }
            return Archive(entries: entries, size: size, directoryOffset: directoryOffset)
        }

        /// Walks the extra field's records; a record that runs past the end counts as zip64
        /// (nothing can be said about such an entry).
        private static func hasZip64Extra(_ extra: Data) -> Bool {
            var position = 0
            while position < extra.count {
                guard position + 4 <= extra.count else { return true }
                let length = Int(extra.le16(position + 2))
                if extra.le16(position) == 0x0001 { return true }
                position += 4 + length
                if position > extra.count { return true }
            }
            return false
        }
    }
}

// MARK: Plain file I/O (POSIX: positioned reads into a reused buffer, no per-chunk allocations)

final class FileReader {
    private let descriptor: Int32
    let size: UInt64

    init(_ url: URL) throws {
        descriptor = open(url.path, O_RDONLY)
        guard descriptor >= 0 else { throw DeltaPack.Unusable("cannot open \(url.lastPathComponent): \(String(cString: strerror(errno)))") }
        var info = stat()
        guard fstat(descriptor, &info) == 0 else {
            close(descriptor)
            throw DeltaPack.Unusable("cannot stat \(url.lastPathComponent)")
        }
        size = UInt64(info.st_size)
    }

    deinit { close(descriptor) }

    func read(at offset: UInt64, into buffer: UnsafeMutableRawBufferPointer) throws {
        var done = 0
        while done < buffer.count {
            let count = pread(descriptor, buffer.baseAddress! + done, buffer.count - done, off_t(offset) + off_t(done))
            if count < 0 && errno == EINTR { continue }
            guard count > 0 else { throw DeltaPack.Unusable("archive is shorter than its directory says") }
            done += count
        }
    }

    func read(at offset: UInt64, count: Int) throws -> Data {
        var data = Data(count: count)
        try data.withUnsafeMutableBytes { try read(at: offset, into: $0) }
        return data
    }
}

/// Sequential output file that hashes what it writes.
final class FileSink {
    private var descriptor: Int32
    private var hasher: Digest.Hasher?
    private(set) var size: UInt64 = 0

    /// `hashing: false` for scratch files whose SHA-256 nobody needs (`finish` then returns "").
    init(_ url: URL, hashing: Bool = true) throws {
        hasher = hashing ? Digest.Hasher(.sha256) : nil
        try? FileManager.default.removeItem(at: url)
        descriptor = open(url.path, O_WRONLY | O_CREAT | O_TRUNC, 0o600)
        guard descriptor >= 0 else { throw DeltaPack.Unusable("cannot create \(url.lastPathComponent): \(String(cString: strerror(errno)))") }
    }

    deinit { close() }

    func write(_ data: Data) throws {
        try data.withUnsafeBytes { try write($0) }
    }

    func write(_ bytes: UnsafeRawBufferPointer) throws {
        var done = 0
        while done < bytes.count {
            let count = Foundation.write(descriptor, bytes.baseAddress! + done, bytes.count - done)
            if count < 0 && errno == EINTR { continue }
            guard count > 0 else { throw DeltaPack.Unusable("cannot write the pack: \(String(cString: strerror(errno)))") }
            done += count
        }
        if hasher != nil { hasher!.update(Data(bytes: bytes.baseAddress!, count: bytes.count)) }
        size += UInt64(bytes.count)
    }

    func close() {
        guard descriptor >= 0 else { return }
        _ = Foundation.close(descriptor)
        descriptor = -1
    }

    /// Closes the file and returns the SHA-256 of everything written.
    func finish() -> String {
        close()
        return hasher?.finalize().hex ?? ""
    }
}

extension Data {
    mutating func append16(_ value: UInt16) {
        append(contentsOf: [UInt8(value & 0xFF), UInt8(value >> 8)])
    }

    mutating func append32(_ value: UInt32) {
        append16(UInt16(value & 0xFFFF))
        append16(UInt16(value >> 16))
    }
}
