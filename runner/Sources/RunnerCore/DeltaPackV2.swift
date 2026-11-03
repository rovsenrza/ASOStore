import Foundation
import Dispatch

/// Delta v3: shared core + team patch, pack format `delta-v2` (docs/adr/DELTA_V3.md).
///
/// The tree is classified as for `delta-v1` tree packs (kind 0: the base supplies the entry). A
/// changed Mach-O with variable ranges (MachORanges) is cut into segments: its team segments are
/// compressed into `Payload/.delta-team.bin`, its core segments are referenced in the artifact's
/// shared core blob (kind 2). Every other changed file travels whole and deflated (kind 1). When
/// the lease offers no core, one is built from this tree (`CoreSource.build`) and must be uploaded
/// before the pack. Like every delta path, any doubt ends in `.fallback(reason)`, never in a failed job.
extension DeltaPack {
    public static let formatV2 = "delta-v2"
    /// Team chunks of the pack's segmented entries; stored, never part of the final IPA.
    public static let teamBlobName = "Payload/.delta-team.bin"

    /// The core manifest (spec "Core"): per entry, its core segments and where their chunks are in the blob.
    public struct CoreManifest: Codable, Sendable, Equatable {
        /// `[start, end, sha256_hex_of_uncompressed_bytes, blob_offset, blob_length, final]`.
        public struct Item: Codable, Sendable, Equatable {
            public var start: UInt64
            public var end: UInt64
            public var sha256: String
            public var blobOffset: UInt64
            public var blobLength: UInt64
            public var final: Bool

            public init(start: UInt64, end: UInt64, sha256: String, blobOffset: UInt64, blobLength: UInt64, final: Bool) {
                self.start = start
                self.end = end
                self.sha256 = sha256
                self.blobOffset = blobOffset
                self.blobLength = blobLength
                self.final = final
            }

            public init(from decoder: Decoder) throws {
                var container = try decoder.unkeyedContainer()
                start = try container.decode(UInt64.self)
                end = try container.decode(UInt64.self)
                sha256 = try container.decode(String.self).lowercased()
                blobOffset = try container.decode(UInt64.self)
                blobLength = try container.decode(UInt64.self)
                final = try container.decode(Bool.self)
            }

            public func encode(to encoder: Encoder) throws {
                var container = encoder.unkeyedContainer()
                try container.encode(start)
                try container.encode(end)
                try container.encode(sha256)
                try container.encode(blobOffset)
                try container.encode(blobLength)
                try container.encode(final)
            }
        }

        public var version: Int
        public var artifactSHA256: String
        public var entries: [String: [Item]]

        public init(version: Int = 1, artifactSHA256: String, entries: [String: [Item]]) {
            self.version = version
            self.artifactSHA256 = artifactSHA256
            self.entries = entries
        }

        public init(from decoder: Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            version = try container.decodeIfPresent(Int.self, forKey: .version) ?? 1
            artifactSHA256 = try container.decodeIfPresent(String.self, forKey: .artifactSHA256) ?? ""
            if let map = try? container.decode([String: [Item]].self, forKey: .entries) {
                entries = map
            } else if let list = try? container.decode([Item].self, forKey: .entries), list.isEmpty {
                entries = [:] // PHP's json_encode writes an empty map as []
            } else {
                throw DecodingError.dataCorruptedError(forKey: .entries, in: container, debugDescription: "core manifest entries are not a map")
            }
        }

        enum CodingKeys: String, CodingKey { case version, artifactSHA256 = "artifact_sha256", entries }

        /// Compact JSON, entries in name order: equal cores give equal manifests.
        public func json() -> Data {
            var json = Data("{\"version\":\(version),\"artifact_sha256\":\"\(escape(artifactSHA256))\",\"entries\":{".utf8)
            for (index, name) in entries.keys.sorted(by: { Data($0.utf8).lexicographicallyPrecedes(Data($1.utf8)) }).enumerated() {
                if index > 0 { json.append(UInt8(ascii: ",")) }
                let items = entries[name]!.map { "[\($0.start),\($0.end),\"\($0.sha256)\",\($0.blobOffset),\($0.blobLength),\($0.final)]" }
                json.append(Data("\"\(escape(name))\":[\(items.joined(separator: ","))]".utf8))
            }
            json.append(Data("}}".utf8))
            return json
        }
    }

    /// Where the core segments' chunks come from.
    public enum CoreSource: Sendable {
        /// The lease's core: entries whose core segments match it exactly are segmented.
        case reuse(sha256: String, manifest: CoreManifest)
        /// No core yet: build one from this tree into `output` (uploaded before the pack).
        case build(output: URL)
    }

    /// A core built by this run: the blob file, its SHA-256 and its manifest JSON.
    public struct CoreUpload: Sendable {
        public var blob: URL
        public var sha256: String
        public var size: Int64
        public var manifest: Data
    }

    public struct ResultV2: Sendable {
        public var pack: Result
        /// A core built by this run; it must be uploaded before the pack. Nil when the pack reuses
        /// the lease's core or references none.
        public var core: CoreUpload?
        /// The core the pack's `"c"` segments point into (the manifest's `core_sha256`).
        public var coreSHA256: String?
        public var entriesSegmented: Int
        public var teamBytes: Int64

        public var report: [String: String] {
            pack.report.merging([
                "delta_format": DeltaPack.formatV2,
                "entries_segmented": String(entriesSegmented),
                "team_bytes": String(teamBytes),
                "core": core != nil ? "built" : (coreSHA256 != nil ? "reused" : "unused"),
                "core_bytes": String(core?.size ?? 0),
            ]) { _, new in new }
        }
    }

    public enum OutcomeV2: Sendable {
        case pack(ResultV2)
        case fallback(String)

        public var result: ResultV2? {
            if case .pack(let result) = self { return result }
            return nil
        }

        public var reason: String? {
            if case .fallback(let reason) = self { return reason }
            return nil
        }
    }

    /// Builds a `delta-v2` pack for the signed tree. Never throws: when delta v2 cannot be used the
    /// answer is `.fallback(reason)` and `output` (and a core being built) is removed.
    public static func buildV2FromTree(source: URL, treeRoot: URL, output: URL, baseSHA256: String, core: CoreSource) -> OutcomeV2 {
        let started = Date()
        func discard() {
            try? FileManager.default.removeItem(at: output)
            if case .build(let blob) = core { try? FileManager.default.removeItem(at: blob) }
        }
        do {
            return .pack(try writeV2(source: source, treeRoot: treeRoot, output: output, baseSHA256: baseSHA256.lowercased(), core: core, started: started))
        } catch let error as Unusable {
            discard()
            return .fallback(error.reason)
        } catch {
            discard()
            return .fallback("I/O error: \(error)")
        }
    }

    // MARK: Planning one entry

    /// What one pack entry (kind 1 or 2) becomes, before the pack is assembled.
    struct EntryPlan {
        struct Part {
            var team: Bool
            /// Team: inside this entry's team file (moved into the team blob later). Core: inside the
            /// lease's blob (reuse) or this entry's core file (build, moved into the new blob later).
            var offset: UInt64
            var length: UInt64
        }

        var size: UInt64
        var crc: UInt32 = 0
        /// Kind 1: the whole file deflated.
        var whole: URL?
        var wholeLength: UInt64 = 0
        /// Kind 2.
        var parts: [Part] = []
        var team: URL?
        var teamLength: UInt64 = 0
        var core: URL?
        var coreLength: UInt64 = 0
        var coreItems: [CoreManifest.Item] = []

        var segmented: Bool { !parts.isEmpty }
    }

    static func isHexDigest(_ text: String) -> Bool {
        text.count == 64 && text.allSatisfy { $0.isHexDigit }
    }

    /// Reads `range` of `reader` piece by piece into `buffer`.
    static func forEachPiece(_ reader: FileReader, _ range: Range<UInt64>, buffer: UnsafeMutableRawBufferPointer,
                             cancellation: Cancellation?, _ body: (UnsafeRawBufferPointer) throws -> Void) throws {
        var offset = range.lowerBound
        while offset < range.upperBound {
            if cancellation?.isCancelled == true { throw Unusable("cancelled") }
            let count = Int(min(UInt64(buffer.count), range.upperBound - offset))
            let piece = UnsafeMutableRawBufferPointer(rebasing: buffer[0..<count])
            try reader.read(at: offset, into: piece)
            try body(UnsafeRawBufferPointer(piece))
            offset += UInt64(count)
        }
    }

    static func planEntry(_ file: TreeFile, index: Int, core: CoreSource, parts folder: URL, cancellation: Cancellation?) throws -> EntryPlan {
        let input = UnsafeMutableRawBufferPointer.allocate(byteCount: copyChunk, alignment: 16)
        let scratch = UnsafeMutableRawBufferPointer.allocate(byteCount: copyChunk, alignment: 16)
        defer { input.deallocate(); scratch.deallocate() }
        let reader = try FileReader(URL(fileURLWithPath: file.path))
        guard reader.size == file.size else { throw Unusable("a file changed while the pack was built") }
        var plan = EntryPlan(size: file.size)
        let name = String(decoding: file.name, as: UTF8.self)

        if let variable = MachORanges.variableRanges(reader) {
            let segments = MachORanges.segments(length: file.size, variable: variable)
            let coreSegments = segments.indices.filter { !segments[$0].team }
            if !coreSegments.isEmpty {
                switch core {
                case .reuse(_, let manifest):
                    if let items = manifest.entries[name], items.count == coreSegments.count,
                       zip(coreSegments, items).allSatisfy({ position, item in
                           segments[position].range == item.start ..< item.end && item.final == (position == segments.count - 1) && item.blobLength > 0
                       }) {
                        // Pass 1: the whole file's CRC and each core segment's SHA-256.
                        var crc: UInt32 = 0
                        var matches = true
                        var coreIndex = 0
                        for segment in segments {
                            var hasher = segment.team ? nil : Digest.Hasher(.sha256)
                            try forEachPiece(reader, segment.range, buffer: input, cancellation: cancellation) { piece in
                                crc = crc32(piece, crc: crc)
                                if hasher != nil { hasher!.update(Data(piece)) }
                            }
                            if let hasher {
                                if hasher.finalize().hex != items[coreIndex].sha256 { matches = false; break }
                                coreIndex += 1
                            }
                        }
                        if matches {
                            // Pass 2: only the team segments are compressed.
                            let teamURL = folder.appendingPathComponent("\(index).team")
                            let team = try FileSink(teamURL, hashing: false)
                            defer { team.close() }
                            coreIndex = 0
                            for (position, segment) in segments.enumerated() {
                                if segment.team {
                                    let length = try RawDeflate.chunk(reader, start: segment.range.lowerBound, end: segment.range.upperBound,
                                                                      final: position == segments.count - 1, input: input, scratch: scratch,
                                                                      cancellation: cancellation) { try team.write($0) }
                                    plan.parts.append(.init(team: true, offset: team.size - length, length: length))
                                } else {
                                    let item = items[coreIndex]
                                    plan.parts.append(.init(team: false, offset: item.blobOffset, length: item.blobLength))
                                    coreIndex += 1
                                }
                            }
                            team.close()
                            plan.crc = crc
                            plan.team = teamURL
                            plan.teamLength = team.size
                            return plan
                        }
                    }
                case .build:
                    let teamURL = folder.appendingPathComponent("\(index).team")
                    let coreURL = folder.appendingPathComponent("\(index).core")
                    let team = try FileSink(teamURL, hashing: false)
                    let coreSink = try FileSink(coreURL, hashing: false)
                    defer { team.close(); coreSink.close() }
                    var crc: UInt32 = 0
                    for (position, segment) in segments.enumerated() {
                        let final = position == segments.count - 1
                        var hasher = segment.team ? nil : Digest.Hasher(.sha256)
                        let sink = segment.team ? team : coreSink
                        let length = try RawDeflate.chunk(reader, start: segment.range.lowerBound, end: segment.range.upperBound, final: final,
                                                          input: input, scratch: scratch, cancellation: cancellation,
                                                          observe: { piece in
                                                              crc = crc32(piece, crc: crc)
                                                              if hasher != nil { hasher!.update(Data(piece)) }
                                                          }) { try sink.write($0) }
                        plan.parts.append(.init(team: segment.team, offset: sink.size - length, length: length))
                        if let hasher {
                            plan.coreItems.append(.init(start: segment.range.lowerBound, end: segment.range.upperBound, sha256: hasher.finalize().hex,
                                                        blobOffset: sink.size - length, blobLength: length, final: final))
                        }
                    }
                    team.close(); coreSink.close()
                    plan.crc = crc
                    plan.team = teamURL
                    plan.teamLength = team.size
                    plan.core = coreURL
                    plan.coreLength = coreSink.size
                    return plan
                }
            }
        }

        // Kind 1: the whole file, one deflate stream.
        let wholeURL = folder.appendingPathComponent("\(index).whole")
        let whole = try FileSink(wholeURL, hashing: false)
        defer { whole.close() }
        var crc: UInt32 = 0
        if file.size == 0 {
            try whole.write(try RawDeflate.chunk(Data(), final: true))
        } else {
            try RawDeflate.chunk(reader, start: 0, end: file.size, final: true, input: input, scratch: scratch, cancellation: cancellation,
                                 observe: { crc = crc32($0, crc: crc) }) { try whole.write($0) }
        }
        whole.close()
        plan.crc = crc
        plan.whole = wholeURL
        plan.wholeLength = whole.size
        return plan
    }

    /// Plans every pack entry, spread over the cores (big files first).
    static func planEntries(_ files: [TreeFile], at indexes: [Int], core: CoreSource, parts: URL) throws -> [Int: EntryPlan] {
        final class Results: @unchecked Sendable {
            private let lock = NSLock()
            private var values: [Int: EntryPlan] = [:]
            private var error: Error?
            var failed: Bool { lock.withLock { error != nil } }
            func set(_ index: Int, _ plan: EntryPlan) { lock.withLock { values[index] = plan } }
            func fail(_ failure: Error) { lock.withLock { if error == nil { error = failure } } }
            func finish() throws -> [Int: EntryPlan] { try lock.withLock { if let error { throw error }; return values } }
        }
        let results = Results()
        let cancellation = Cancellation.current
        let order = indexes.sorted { files[$0].size > files[$1].size }
        DispatchQueue.concurrentPerform(iterations: order.count) { position in
            guard !results.failed else { return }
            let index = order[position]
            do {
                results.set(index, try planEntry(files[index], index: index, core: core, parts: parts, cancellation: cancellation))
            } catch {
                results.fail(error)
            }
        }
        return try results.finish()
    }

    // MARK: Writing

    /// Appends the file at `url` to `sink`; `observe` sees every byte.
    static func append(_ url: URL, length: UInt64, to sink: FileSink, buffer: UnsafeMutableRawBufferPointer, observe: (UnsafeRawBufferPointer) -> Void = { _ in }) throws {
        let reader = try FileReader(url)
        guard reader.size == length else { throw Unusable("a scratch file has the wrong size") }
        try forEachPiece(reader, 0 ..< length, buffer: buffer, cancellation: Cancellation.current) { piece in
            observe(piece)
            try sink.write(piece)
        }
    }

    private static func writeV2(source: URL, treeRoot: URL, output: URL, baseSHA256: String, core: CoreSource, started: Date) throws -> ResultV2 {
        guard isHexDigest(baseSHA256) else { throw Unusable("base SHA-256 is not a hex digest") }
        if case .reuse(let sha256, _) = core, !isHexDigest(sha256) { throw Unusable("the lease's core SHA-256 is not a hex digest") }
        let classified = try classifyTree(source: source, treeRoot: treeRoot, reserved: [manifestName, teamBlobName], allowNonASCII: true)
        let files = classified.files
        let packIndexes = classified.fromBase.indices.filter { !classified.fromBase[$0] }
        guard packIndexes.count + 2 < maxEntries else { throw Unusable("pack would need more than 65534 entries") }

        let parts = output.appendingPathExtension("parts")
        try? FileManager.default.removeItem(at: parts)
        try FileManager.default.createDirectory(at: parts, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: parts) }

        var plans = try planEntries(files, at: packIndexes, core: core, parts: parts)
        let segmentedIndexes = packIndexes.filter { plans[$0]!.segmented }
        let wholeIndexes = packIndexes.filter { !plans[$0]!.segmented }

        let buffer = UnsafeMutableRawBufferPointer.allocate(byteCount: copyChunk, alignment: 16)
        defer { buffer.deallocate() }

        // The team blob and (when building) the core blob, in tree order; offsets become global.
        let teamURL = parts.appendingPathComponent("team.bin")
        let team = try FileSink(teamURL, hashing: false)
        var teamCRC: UInt32 = 0
        var coreSink: FileSink?
        var coreURL: URL?
        if case .build(let blob) = core, !segmentedIndexes.isEmpty {
            coreSink = try FileSink(blob)
            coreURL = blob
        }
        var coreEntries: [String: [CoreManifest.Item]] = [:]
        do {
            for index in segmentedIndexes {
                var plan = plans[index]!
                let teamBase = team.size, coreBase = coreSink?.size ?? 0
                if let url = plan.team, plan.teamLength > 0 {
                    try append(url, length: plan.teamLength, to: team, buffer: buffer) { teamCRC = crc32($0, crc: teamCRC) }
                }
                if let coreSink, let url = plan.core, plan.coreLength > 0 {
                    try append(url, length: plan.coreLength, to: coreSink, buffer: buffer)
                }
                for position in plan.parts.indices {
                    if plan.parts[position].team {
                        plan.parts[position].offset += teamBase
                    } else if coreSink != nil {
                        plan.parts[position].offset += coreBase
                    }
                }
                if coreSink != nil {
                    coreEntries[String(decoding: files[index].name, as: UTF8.self)] = plan.coreItems.map {
                        var item = $0
                        item.blobOffset += coreBase
                        return item
                    }
                }
                plans[index] = plan
            }
        } catch {
            team.close()
            coreSink?.close()
            throw error
        }
        team.close()
        let teamLength = team.size

        var upload: CoreUpload?
        var coreSHA256: String?
        if let coreSink, let coreURL {
            let size = coreSink.size
            let sha256 = coreSink.finish()
            // The backend refuses a core larger than the source IPA.
            let sourceSize = try FileReader(source).size
            guard size <= sourceSize else { throw Unusable("core of \(size) bytes is larger than the \(sourceSize) byte source IPA") }
            let manifest = CoreManifest(artifactSHA256: baseSHA256, entries: coreEntries).json()
            upload = CoreUpload(blob: coreURL, sha256: sha256, size: Int64(size), manifest: manifest)
            coreSHA256 = sha256
        } else if case .reuse(let sha256, _) = core, !segmentedIndexes.isEmpty {
            coreSHA256 = sha256.lowercased()
        }

        let manifest = try manifestV2JSON(baseSHA256: baseSHA256, coreSHA256: coreSHA256, files: files, fromBase: classified.fromBase, plans: plans)

        // Known before the pack is written.
        let manifestName = Data(Self.manifestName.utf8), teamName = Data(Self.teamBlobName.utf8)
        var expected = UInt64(30 + 46 + 2 * manifestName.count + manifest.count) + 22
        expected += UInt64(30 + 46 + 2 * teamName.count) + teamLength
        for index in wholeIndexes { expected += UInt64(30 + 46 + 2 * files[index].name.count) + plans[index]!.wholeLength }
        let total = files.reduce(UInt64(0)) { $0 + $1.size }
        guard expected < maxArchiveBytes else { throw Unusable("pack would be 4 GiB or more") }
        // Segmented entries make a pack worth it; without one the v1 rule holds.
        if segmentedIndexes.isEmpty, expected * 2 >= total {
            throw Unusable("pack of \(expected) bytes with no segmented entry is not under half of the \(total) byte tree")
        }

        let sink = try FileSink(output)
        var directory = Data()
        var written: UInt64 = 0
        func entry(name: Data, method: UInt16, crc: UInt32, compressed: UInt64, size: UInt64, pair: RecordPair) throws {
            var local = Data()
            local.append32(0x0403_4B50)
            // A non-ASCII name (never taken from the base) is marked UTF-8, as ZipNames.markUTF8 marks full builds.
            let flags: UInt16 = name.contains(where: { $0 >= 0x80 }) ? 0x0800 : 0
            local.append16(20); local.append16(flags); local.append16(method)    // version, flags, method
            local.append16(0); local.append16(dosDate)
            local.append32(crc)
            local.append32(UInt32(compressed)); local.append32(UInt32(size))
            local.append16(UInt16(name.count)); local.append16(0)
            local.append(name)
            try sink.write(local)

            var record = Data()
            record.append32(0x0201_4B50)
            record.append16(pair.madeBy); record.append16(20)
            record.append16(flags); record.append16(method)
            record.append16(0); record.append16(dosDate)
            record.append32(crc)
            record.append32(UInt32(compressed)); record.append32(UInt32(size))
            record.append16(UInt16(name.count))
            for _ in 0..<4 { record.append16(0) }                           // extra, comment, disk, internal attributes
            record.append32(pair.external)
            record.append32(UInt32(written))
            record.append(name)
            directory.append(record)
            written += UInt64(local.count)
        }
        do {
            for index in wholeIndexes {
                let plan = plans[index]!
                try entry(name: files[index].name, method: 8, crc: plan.crc, compressed: plan.wholeLength, size: plan.size, pair: RecordPair(madeBy: 0, external: 0))
                try append(plan.whole!, length: plan.wholeLength, to: sink, buffer: buffer)
                written += plan.wholeLength
            }
            try entry(name: teamName, method: 0, crc: teamCRC, compressed: teamLength, size: teamLength, pair: RecordPair(madeBy: 3 << 8 | 20, external: 0o100644 << 16))
            if teamLength > 0 { try append(teamURL, length: teamLength, to: sink, buffer: buffer) }
            written += teamLength
            try finish(sink, directory: &directory, written: &written, manifest: manifest, packCount: wholeIndexes.count + 1)
        } catch {
            sink.close()
            throw error
        }
        let sha256 = sink.finish()
        guard written == expected, sink.size == expected else { throw Unusable("pack came out \(sink.size) bytes, expected \(expected)") }

        let check = try Archive.read(output, label: "pack")
        guard check.entries.count == wholeIndexes.count + 2, check.entries.last?.name == manifestName,
              check.entries[check.entries.count - 2].name == teamName,
              zip(check.entries, wholeIndexes).allSatisfy({ $0.name == files[$1].name }) else {
            throw Unusable("pack does not read back as expected")
        }

        let result = Result(sha256: sha256, baseSHA256: baseSHA256, packBytes: Int64(written), signedBytes: Int64(total),
                            entriesTotal: files.count, entriesPack: packIndexes.count, seconds: Date().timeIntervalSince(started))
        return ResultV2(pack: result, core: upload, coreSHA256: coreSHA256, entriesSegmented: segmentedIndexes.count, teamBytes: Int64(teamLength))
    }

    /// `{"version":2,"base_sha256":"…","core_sha256":"…"|null,"made_by":0,"external":0,"entries":[…]}`,
    /// compact, in the tree's order: `["name",0]`, `["name",1]` or
    /// `["name",2,{"crc":…,"size":…,"segments":[["t",offset,length],["c",offset,length],…]}]`.
    static func manifestV2JSON(baseSHA256: String, coreSHA256: String?, files: [TreeFile], fromBase: [Bool], plans: [Int: EntryPlan]) throws -> Data {
        let core = coreSHA256.map { "\"\($0)\"" } ?? "null"
        var json = Data("{\"version\":2,\"base_sha256\":\"\(baseSHA256)\",\"core_sha256\":\(core),\"made_by\":0,\"external\":0,\"entries\":[".utf8)
        for (index, file) in files.enumerated() {
            guard let name = String(data: file.name, encoding: .utf8) else { throw Unusable("entry \(index) has a name that is not valid UTF-8") }
            if index > 0 { json.append(UInt8(ascii: ",")) }
            if fromBase[index] {
                json.append(Data("[\"\(escape(name))\",0]".utf8))
            } else if let plan = plans[index], plan.segmented {
                let segments = plan.parts.map { "[\"\($0.team ? "t" : "c")\",\($0.offset),\($0.length)]" }.joined(separator: ",")
                json.append(Data("[\"\(escape(name))\",2,{\"crc\":\(plan.crc),\"size\":\(plan.size),\"segments\":[\(segments)]}]".utf8))
            } else {
                json.append(Data("[\"\(escape(name))\",1]".utf8))
            }
        }
        json.append(Data("]}".utf8))
        return json
    }
}
