import Foundation
import Testing
import CZlib
@testable import RunnerCore

/// Delta v3, pack format `delta-v2` (docs/adr/DELTA_V3.md): variable ranges, chunks, core reuse and
/// a small composer that follows the spec, so a pack is checked by what it composes to.
@Suite struct DeltaPackV2Tests {
    typealias Entry = DeltaPackTests.TestEntry

    // MARK: Fixtures

    static func le32(_ value: Int) -> Data { Data(DeltaPackTests.le32(value)) }
    static func be32(_ value: Int) -> Data { Data([UInt8(value >> 24 & 0xFF), UInt8(value >> 16 & 0xFF), UInt8(value >> 8 & 0xFF), UInt8(value & 0xFF)]) }

    /// A thin 64-bit Mach-O of `size` bytes with an LC_CODE_SIGNATURE at `signature`. The bytes
    /// outside the first page and the signature (the core) depend only on `body`; the first page
    /// after the load commands and the signature depend on `team`, like two teams' signings.
    static func machO(size: Int, signature: Range<Int>?, team: UInt8, body: UInt8 = 9, magic: [UInt8] = MachORanges.thinMagic) -> Data {
        var data = DeltaPackTests.filler(size, seed: body)
        var header = Data(magic)
        header += le32(0x0100_000C) + le32(0) + le32(2)                    // cputype arm64, subtype, MH_EXECUTE
        header += le32(signature == nil ? 1 : 2) + le32(72 + (signature == nil ? 0 : 16)) + le32(0) + le32(0)
        header += le32(0x19) + le32(72) + Data(count: 64)                  // LC_SEGMENT_64, contents unused
        if let signature { header += le32(0x1D) + le32(16) + le32(signature.lowerBound) + le32(signature.count) }
        data.replaceSubrange(0 ..< header.count, with: header)
        let page = min(4096, size)
        data.replaceSubrange(header.count ..< page, with: DeltaPackTests.filler(page - header.count, seed: team))
        if let signature { data.replaceSubrange(signature, with: DeltaPackTests.filler(signature.count, seed: team &+ 100)) }
        return data
    }

    /// A fat file: the big-endian FAT_MAGIC header, then each slice at its offset.
    static func fat(_ slices: [(offset: Int, data: Data)], size: Int, body: UInt8 = 5) -> Data {
        var data = DeltaPackTests.filler(size, seed: body)
        var header = Data(MachORanges.fatMagic) + be32(slices.count)
        for slice in slices { header += be32(0x0100_000C) + be32(0) + be32(slice.offset) + be32(slice.data.count) + be32(14) }
        data.replaceSubrange(0 ..< header.count, with: header)
        for slice in slices { data.replaceSubrange(slice.offset ..< slice.offset + slice.data.count, with: slice.data) }
        return data
    }

    /// Inflates one raw DEFLATE stream; the stream must end exactly at the end of `data`.
    static func inflateRaw(_ data: Data) throws -> Data {
        let stream = UnsafeMutablePointer<z_stream>.allocate(capacity: 1)
        stream.initialize(to: z_stream())
        defer { inflateEnd(stream); stream.deallocate() }
        try #require(inflateInit2_(stream, -15, zlibVersion(), Int32(MemoryLayout<z_stream>.size)) == Z_OK)
        var out = Data()
        var buffer = [UInt8](repeating: 0, count: 1 << 16)
        var status: Int32 = Z_OK
        try data.withUnsafeBytes { input in
            stream.pointee.next_in = UnsafeMutablePointer(mutating: input.bindMemory(to: Bytef.self).baseAddress)
            stream.pointee.avail_in = UInt32(input.count)
            repeat {
                status = buffer.withUnsafeMutableBufferPointer { output in
                    stream.pointee.next_out = output.baseAddress
                    stream.pointee.avail_out = UInt32(output.count)
                    let status = inflate(stream, Z_NO_FLUSH)
                    out.append(output.baseAddress!, count: output.count - Int(stream.pointee.avail_out))
                    return status
                }
                try #require(status == Z_OK || status == Z_STREAM_END, "inflate status \(status)")
            } while status != Z_STREAM_END
        }
        #expect(stream.pointee.avail_in == 0, "data after the final block")
        return out
    }

    // MARK: Variable ranges and segments

    @Test func thinRangesAreTheFirstPageAndTheSignature() throws {
        let file = Self.machO(size: 20_000, signature: 16_000 ..< 18_000, team: 1)
        let ranges = try #require(MachORanges.variableRanges(file))
        #expect(ranges == [0 ..< 4096, 16_000 ..< 18_000])
        let segments = MachORanges.segments(length: 20_000, variable: ranges)
        #expect(segments == [
            .init(team: true, range: 0 ..< 4096), .init(team: false, range: 4096 ..< 16_000),
            .init(team: true, range: 16_000 ..< 18_000), .init(team: false, range: 18_000 ..< 20_000),
        ])

        // A signature at the end: the file ends with a team segment.
        let tail = try #require(MachORanges.variableRanges(Self.machO(size: 20_000, signature: 18_000 ..< 20_000, team: 1)))
        #expect(MachORanges.segments(length: 20_000, variable: tail).last == .init(team: true, range: 18_000 ..< 20_000))
    }

    @Test func adjacentAndOverlappingRangesMerge() throws {
        #expect(MachORanges.variableRanges(Self.machO(size: 10_000, signature: 4096 ..< 6000, team: 1)) == [0 ..< 6000])
        #expect(MachORanges.variableRanges(Self.machO(size: 10_000, signature: 3000 ..< 7000, team: 1)) == [0 ..< 7000])
        // A file shorter than a page: the first range ends at the file's end.
        #expect(MachORanges.variableRanges(Self.machO(size: 3000, signature: 2000 ..< 2500, team: 1)) == [0 ..< 3000])
    }

    @Test func fatRangesArePerSlice() throws {
        let one = Self.machO(size: 12_000, signature: 10_000 ..< 11_000, team: 1, body: 2)
        let two = Self.machO(size: 9_000, signature: 8_000 ..< 9_000, team: 1, body: 3)
        let file = Self.fat([(16_384, one), (32_768, two)], size: 32_768 + 9_000)
        let ranges = try #require(MachORanges.variableRanges(file))
        // The fat header's own page is not variable (proto.py): only each slice's first page and signature.
        #expect(ranges == [16_384 ..< 20_480, 26_384 ..< 27_384, 32_768 ..< 36_864, 40_768 ..< 41_768])
    }

    @Test func otherFilesHaveNoVariableRanges() throws {
        #expect(MachORanges.variableRanges(Data("not a binary at all".utf8)) == nil)
        #expect(MachORanges.variableRanges(Data()) == nil)
        // No LC_CODE_SIGNATURE, a 32-bit magic, a signature past the end, an empty signature.
        #expect(MachORanges.variableRanges(Self.machO(size: 10_000, signature: nil, team: 1)) == nil)
        #expect(MachORanges.variableRanges(Self.machO(size: 10_000, signature: 8000 ..< 9000, team: 1, magic: [0xCE, 0xFA, 0xED, 0xFE])) == nil)
        var past = Self.machO(size: 10_000, signature: 8000 ..< 9000, team: 1)
        past.replaceSubrange(32 + 72 + 12 ..< 32 + 72 + 16, with: Self.le32(5000))
        #expect(MachORanges.variableRanges(past) == nil)
        var empty = past
        empty.replaceSubrange(32 + 72 + 12 ..< 32 + 72 + 16, with: Self.le32(0))
        #expect(MachORanges.variableRanges(empty) == nil)
        // A fat slice that is not MH_MAGIC_64 (or a Java class file, same magic): nothing.
        #expect(MachORanges.variableRanges(Self.fat([(4096, Data(repeating: 7, count: 5000))], size: 9096)) == nil)
        // Big-endian thin Mach-O (MH_CIGAM_64) is not covered.
        #expect(MachORanges.variableRanges(Self.machO(size: 10_000, signature: 8000 ..< 9000, team: 1, magic: [0xFE, 0xED, 0xFA, 0xCF])) == nil)
    }

    // MARK: Chunks

    @Test func concatenatedChunksInflateToTheFile() throws {
        // Compressible and incompressible parts, over several 1 MiB read pieces.
        var file = DeltaPackTests.filler(1_200_000, seed: 4)
        file.append(Data(repeating: 0x41, count: 1_500_000))
        file.append(DeltaPackTests.filler(10, seed: 8))
        let folder = DeltaPackTests.Folder()
        let url = try folder.write(file, "file.bin")
        let reader = try FileReader(url)
        let input = UnsafeMutableRawBufferPointer.allocate(byteCount: 1 << 20, alignment: 16)
        let scratch = UnsafeMutableRawBufferPointer.allocate(byteCount: 1 << 20, alignment: 16)
        defer { input.deallocate(); scratch.deallocate() }

        let cuts: [UInt64] = [0, 4096, 1_100_000, 2_700_000, 2_700_003, UInt64(file.count)]
        var stream = Data()
        var seen = Data()
        for index in 0 ..< cuts.count - 1 {
            var chunk = Data()
            let length = try RawDeflate.chunk(reader, start: cuts[index], end: cuts[index + 1], final: index == cuts.count - 2,
                                              input: input, scratch: scratch, observe: { seen.append(contentsOf: $0) }) { chunk.append(contentsOf: $0) }
            #expect(Int(length) == chunk.count)
            // Each chunk is self-contained: it inflates alone (a non-final chunk via an added final block).
            let alone = index == cuts.count - 2 ? chunk : chunk + Data([0x03, 0x00])
            #expect(try Self.inflateRaw(alone) == file[Int(cuts[index]) ..< Int(cuts[index + 1])])
            stream.append(chunk)
        }
        #expect(seen == file)
        #expect(try Self.inflateRaw(stream) == file)
        // The in-memory form agrees with the file form.
        #expect(try Self.inflateRaw(RawDeflate.chunk(file.prefix(5000), final: true)) == file.prefix(5000))
        #expect(try Self.inflateRaw(RawDeflate.chunk(Data(), final: true)).isEmpty)
    }

    // MARK: Packs

    /// The spec's composition: every final entry from the base (0), the pack (1) or its segments (2).
    static func compose(pack: URL, source: URL, core: Data?) throws -> (files: [String: Data], manifest: [String: Any]) {
        func payloads(_ url: URL) throws -> [String: (entry: DeltaPack.Archive.Entry, data: Data)] {
            let bytes = try Data(contentsOf: url)
            let archive = try DeltaPack.Archive.read(url, label: "test")
            var out: [String: (DeltaPack.Archive.Entry, Data)] = [:]
            for entry in archive.entries {
                let offset = Int(entry.localOffset)
                let start = offset + 30 + Int(bytes.le16(offset + 26)) + Int(bytes.le16(offset + 28))
                out[String(decoding: entry.name, as: UTF8.self)] = (entry, Data(bytes[start ..< start + Int(entry.compressedSize)]))
            }
            return out
        }
        let packed = try payloads(pack)
        let base = try payloads(source)
        let manifestData = try #require(packed[DeltaPack.manifestName]).data
        let manifest = try #require(try JSONSerialization.jsonObject(with: manifestData) as? [String: Any])
        let team = try #require(packed[DeltaPack.teamBlobName])
        #expect(team.entry.method == 0)

        var files: [String: Data] = [:]
        for item in try #require(manifest["entries"] as? [[Any]]) {
            let name = try #require(item[0] as? String)
            switch try #require(item[1] as? Int) {
            case 0:
                let original = try #require(base[name])
                files[name] = original.entry.method == 8 ? try inflateRaw(original.data) : original.data
            case 1:
                let entry = try #require(packed[name])
                #expect(entry.entry.method == 8)
                #expect(entry.entry.flags & 8 == 0)
                let data = try inflateRaw(entry.data)
                #expect(DeltaPack.crc32(data) == entry.entry.crc)
                #expect(Int(entry.entry.uncompressedSize) == data.count)
                files[name] = data
            case 2:
                let info = try #require(item[2] as? [String: Any])
                var stream = Data()
                for segment in try #require(info["segments"] as? [[Any]]) {
                    let offset = try #require(segment[1] as? Int), length = try #require(segment[2] as? Int)
                    #expect(length > 0)
                    switch try #require(segment[0] as? String) {
                    case "t": stream.append(team.data[offset ..< offset + length])
                    case "c": stream.append(try #require(core)[offset ..< offset + length])
                    default: Issue.record("unknown segment kind")
                    }
                }
                let data = try inflateRaw(stream)
                #expect(data.count == (try #require(info["size"] as? Int)))
                #expect(Int(DeltaPack.crc32(data)) == (try #require(info["crc"] as? Int)))
                files[name] = data
            default:
                Issue.record("unknown kind for \(name)")
            }
        }
        return (files, manifest)
    }

    static func kinds(_ manifest: [String: Any]) -> [String: Int] {
        Dictionary(uniqueKeysWithValues: (manifest["entries"] as! [[Any]]).map { ($0[0] as! String, $0[1] as! Int) })
    }

    struct App {
        var source: [Entry]
        var tree: [String: Data]
    }

    /// The app as the source holds it, and the tree signed for `team`.
    static func app(team: UInt8) -> App {
        let big = DeltaPackTests.filler(300_000, seed: 1)
        let slices = { (team: UInt8) in
            Self.fat([(16_384, Self.machO(size: 40_000, signature: 36_000 ..< 39_000, team: team, body: 2)),
                      (57_344, Self.machO(size: 30_000, signature: 27_000 ..< 30_000, team: team, body: 3))], size: 57_344 + 30_000)
        }
        let source = [
            Entry("Payload/"), Entry("Payload/A.app/"),
            Entry("Payload/A.app/A", Self.machO(size: 200_000, signature: 190_000 ..< 196_000, team: 200)),
            Entry("Payload/A.app/Frameworks/F.framework/F", slices(201)),
            Entry("Payload/A.app/big.bin", big),
            Entry("Payload/A.app/Info.plist", Data("<plist>old</plist>".utf8)),
            Entry("Payload/A.app/note.txt", Data("old note".utf8), method: 0),
        ]
        let tree: [String: Data] = [
            "Payload/A.app/A": Self.machO(size: 200_000, signature: 190_000 ..< 196_000, team: team),
            "Payload/A.app/Frameworks/F.framework/F": slices(team),
            "Payload/A.app/big.bin": big,
            "Payload/A.app/Info.plist": Data("<plist>team \(team)</plist>".utf8),
            "Payload/A.app/embedded.mobileprovision": Data("profile \(team)".utf8),
            "Payload/A.app/note.txt": Data("new note".utf8),
            "Payload/A.app/empty.txt": Data(),
        ]
        return App(source: source, tree: tree)
    }

    struct Built {
        var folder: DeltaPackTests.Folder
        var source: URL
        var output: URL
        var outcome: DeltaPack.OutcomeV2
    }

    static func build(_ app: App, core: (DeltaPackTests.Folder) -> DeltaPack.CoreSource) throws -> Built {
        let folder = DeltaPackTests.Folder()
        let source = try folder.write(DeltaPackTests.zip(app.source), "source.ipa")
        let root = try DeltaPackTreeTests.tree(app.tree, in: folder)
        let output = folder.url.appendingPathComponent("delta.pack")
        let outcome = DeltaPack.buildV2FromTree(source: source, treeRoot: root, output: output, baseSHA256: DeltaPackTests.base64, core: core(folder))
        return Built(folder: folder, source: source, output: output, outcome: outcome)
    }

    @Test func buildsACoreAndAPackThatComposeToTheTree() throws {
        let app = Self.app(team: 1)
        let built = try Self.build(app) { .build(output: $0.url.appendingPathComponent("core.bin")) }
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        let core = try #require(result.core)
        let blob = try Data(contentsOf: core.blob)
        #expect(core.sha256 == RequestSigner.sha256(blob))
        #expect(core.size == Int64(blob.count))
        #expect(result.coreSHA256 == core.sha256)
        #expect(result.entriesSegmented == 2)
        #expect(result.pack.sha256 == (try RequestSigner.sha256(fileAt: built.output)))
        #expect(result.report["core"] == "built")

        let (files, manifest) = try Self.compose(pack: built.output, source: built.source, core: blob)
        #expect(files == app.tree)
        #expect(manifest["version"] as? Int == 2)
        #expect(manifest["core_sha256"] as? String == core.sha256)
        #expect(Self.kinds(manifest) == [
            "Payload/A.app/A": 2, "Payload/A.app/Frameworks/F.framework/F": 2, "Payload/A.app/Info.plist": 1,
            "Payload/A.app/big.bin": 0, "Payload/A.app/embedded.mobileprovision": 1, "Payload/A.app/empty.txt": 1,
            "Payload/A.app/note.txt": 1,
        ])
        let text = try DeltaPackTests.manifest(of: built.output).text
        #expect(text.hasPrefix("{\"version\":2,\"base_sha256\":\"\(DeltaPackTests.base64)\",\"core_sha256\":\"\(core.sha256)\",\"made_by\":0,\"external\":0,\"entries\":[[\"Payload/A.app/A\",2,{\"crc\":"))

        // Pack layout: whole entries deflated, then the stored team blob, then the stored manifest.
        let archive = try DeltaPack.Archive.read(built.output, label: "pack")
        #expect(archive.entries.map { String(decoding: $0.name, as: UTF8.self) } == [
            "Payload/A.app/Info.plist", "Payload/A.app/embedded.mobileprovision", "Payload/A.app/empty.txt", "Payload/A.app/note.txt",
            DeltaPack.teamBlobName, DeltaPack.manifestName,
        ])
        #expect(archive.entries.dropLast(2).allSatisfy { $0.method == 8 && $0.flags == 0 && $0.madeBy == 0 && $0.externalAttributes == 0 })
        #expect(archive.entries.suffix(2).allSatisfy { $0.method == 0 })

        // The core manifest: every core segment of both binaries, hashes of the uncompressed bytes.
        let decoded = try JSONDecoder().decode(DeltaPack.CoreManifest.self, from: core.manifest)
        #expect(decoded.artifactSHA256 == DeltaPackTests.base64)
        #expect(decoded.json() == core.manifest)
        let items = try #require(decoded.entries["Payload/A.app/A"])
        let a = app.tree["Payload/A.app/A"]!
        #expect(items.map { $0.start ..< $0.end } == [4096 ..< 190_000, 196_000 ..< 200_000])
        #expect(items.map(\.final) == [false, true])
        for item in items {
            #expect(item.sha256 == RequestSigner.sha256(a[Int(item.start) ..< Int(item.end)]))
            #expect(try Self.inflateRaw(blob[Int(item.blobOffset) ..< Int(item.blobOffset + item.blobLength)] + (item.final ? Data() : Data([0x03, 0x00])))
                    == a[Int(item.start) ..< Int(item.end)])
        }
        #expect(decoded.entries.count == 2)
    }

    /// Team 1 builds the core; team 2's pack reuses it and composes to team 2's tree.
    @Test func anotherTeamReusesTheCore() throws {
        let first = try Self.build(Self.app(team: 1)) { .build(output: $0.url.appendingPathComponent("core.bin")) }
        let core = try #require(first.outcome.result?.core)
        let blob = try Data(contentsOf: core.blob)
        let manifest = try JSONDecoder().decode(DeltaPack.CoreManifest.self, from: core.manifest)

        let app = Self.app(team: 2)
        let second = try Self.build(app) { _ in .reuse(sha256: core.sha256.uppercased(), manifest: manifest) }
        let result = try #require(second.outcome.result, "fallback: \(second.outcome.reason ?? "")")
        #expect(result.core == nil)
        #expect(result.coreSHA256 == core.sha256)
        #expect(result.entriesSegmented == 2)
        #expect(result.report["core"] == "reused")
        let (files, composed) = try Self.compose(pack: second.output, source: second.source, core: blob)
        #expect(files == app.tree)
        #expect(composed["core_sha256"] as? String == core.sha256)
        // Only the team ranges travel: far less than the binaries.
        #expect(result.teamBytes < 40_000)
    }

    @Test func aCoreThatDoesNotMatchMakesTheEntryWhole() throws {
        let first = try Self.build(Self.app(team: 1)) { .build(output: $0.url.appendingPathComponent("core.bin")) }
        let core = try #require(first.outcome.result?.core)
        let manifest = try JSONDecoder().decode(DeltaPack.CoreManifest.self, from: core.manifest)

        var app = Self.app(team: 2)
        // A: one core byte differs (hash mismatch). F: its signature moved (other boundaries).
        app.tree["Payload/A.app/A"]![100_000] ^= 0xFF
        app.tree["Payload/A.app/Frameworks/F.framework/F"] = Self.fat(
            [(16_384, Self.machO(size: 40_000, signature: 35_000 ..< 39_000, team: 2, body: 2)),
             (57_344, Self.machO(size: 30_000, signature: 27_000 ..< 30_000, team: 2, body: 3))], size: 57_344 + 30_000)
        let built = try Self.build(app) { _ in .reuse(sha256: core.sha256, manifest: manifest) }
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        #expect(result.entriesSegmented == 0)
        // No "c" segment: no core is referenced.
        #expect(result.coreSHA256 == nil)
        let (files, composed) = try Self.compose(pack: built.output, source: built.source, core: nil)
        #expect(files == app.tree)
        #expect(composed["core_sha256"] is NSNull)
        #expect(Self.kinds(composed)["Payload/A.app/A"] == 1)
        #expect(Self.kinds(composed)["Payload/A.app/Frameworks/F.framework/F"] == 1)

        // An entry the core does not list is whole as well, the others still use the core.
        var partial = manifest
        partial.entries.removeValue(forKey: "Payload/A.app/A")
        let mixed = try Self.build(Self.app(team: 3)) { _ in .reuse(sha256: core.sha256, manifest: partial) }
        let mixedResult = try #require(mixed.outcome.result)
        #expect(mixedResult.entriesSegmented == 1)
        let (mixedFiles, mixedManifest) = try Self.compose(pack: mixed.output, source: mixed.source, core: try Data(contentsOf: core.blob))
        #expect(mixedFiles == Self.app(team: 3).tree)
        #expect(Self.kinds(mixedManifest)["Payload/A.app/A"] == 1)
        #expect(Self.kinds(mixedManifest)["Payload/A.app/Frameworks/F.framework/F"] == 2)
    }

    @Test func withoutSegmentedEntriesThePackMustBeUnderHalf() throws {
        // Nothing but changed text: the v1 rule applies and the pack is not worth it.
        let app = App(source: [Entry("Payload/A.app/x.txt", Data("a".utf8))],
                      tree: ["Payload/A.app/x.txt": DeltaPackTests.filler(10_000, seed: 3)])
        let built = try Self.build(app) { .build(output: $0.url.appendingPathComponent("core.bin")) }
        #expect(built.outcome.reason?.contains("not under half") == true)
        #expect(!FileManager.default.fileExists(atPath: built.output.path))
        #expect(!FileManager.default.fileExists(atPath: built.folder.url.appendingPathComponent("core.bin").path))
    }

    @Test func refusesAReservedName() throws {
        var app = Self.app(team: 1)
        app.tree[DeltaPack.teamBlobName] = Data("x".utf8)
        let built = try Self.build(app) { .build(output: $0.url.appendingPathComponent("core.bin")) }
        #expect(built.outcome.reason?.contains(DeltaPack.teamBlobName) == true)
    }

    // MARK: Lease

    static let lease = """
    {"job_id":"j","signed_build_id":"b","bundle_identifier":"com.example.a","team_identifier":"TEAM123456",
     "certificate_sha1":"AAAA","profile":{"uuid":"U","content":""},DELTA
     "source":{"sha256":"0","size_bytes":1,"path":"x"},"upload_path":"u","result_path":"r"}
    """

    static func decode(delta: String) throws -> SigningJob {
        try JSONDecoder().decode(SigningJob.self, from: Data(lease.replacingOccurrences(of: "DELTA", with: delta).utf8))
    }

    @Test func decodesLeasesWithAndWithoutDeltaV2() throws {
        let none = try Self.decode(delta: "")
        #expect(none.delta.enabled == false)
        #expect(none.delta.version == 1)

        let v1 = try Self.decode(delta: #""delta":{"enabled":true},"#)
        #expect(v1.delta.enabled && v1.delta.version == 1 && v1.delta.core == nil && v1.delta.corePath == nil && !v1.delta.coreUnreadable)

        let fresh = try Self.decode(delta: #""delta":{"enabled":true,"version":2,"core":null,"core_path":"/api/worker/v1/jobs/j/core"},"#)
        #expect(fresh.delta.version == 2)
        #expect(fresh.delta.core == nil && !fresh.delta.coreUnreadable)
        #expect(fresh.delta.corePath == "/api/worker/v1/jobs/j/core")

        let sha = String(repeating: "AB", count: 32)
        let offered = try Self.decode(delta: """
        "delta":{"enabled":true,"version":2,"core_path":"/c","core":{"sha256":"\(sha)","manifest":{"version":1,"artifact_sha256":"\(DeltaPackTests.base64)",
         "entries":{"Payload/A.app/A":[[4096,9216000,"\(sha)",0,2711004,false],[9300000,9400000,"cd",2711004,30111,true]]}}}},
        """)
        let core = try #require(offered.delta.core)
        #expect(core.sha256 == sha.lowercased())
        #expect(core.manifest.entries["Payload/A.app/A"] == [
            .init(start: 4096, end: 9_216_000, sha256: sha.lowercased(), blobOffset: 0, blobLength: 2_711_004, final: false),
            .init(start: 9_300_000, end: 9_400_000, sha256: "cd", blobOffset: 2_711_004, blobLength: 30_111, final: true),
        ])

        // PHP writes an empty map as [], and a manifest may arrive as a JSON string.
        let empty = try Self.decode(delta: #""delta":{"enabled":true,"version":2,"core":{"sha256":"aa","manifest":{"version":1,"artifact_sha256":"x","entries":[]}}},"#)
        #expect(empty.delta.core?.manifest.entries.isEmpty == true)
        let string = try Self.decode(delta: #""delta":{"enabled":true,"version":2,"core":{"sha256":"aa","manifest":"{\"version\":1,\"artifact_sha256\":\"x\",\"entries\":{}}"}},"#)
        #expect(string.delta.core?.manifest.artifactSHA256 == "x")

        // A core the runner cannot read never fails the lease, and is not taken for "no core".
        let broken = try Self.decode(delta: #""delta":{"enabled":true,"version":2,"core":{"sha256":"aa","manifest":{"entries":{"x":[[1]]}}},"core_path":"/c"},"#)
        #expect(broken.delta.core == nil && broken.delta.coreUnreadable)
        if case .success = Signer.coreSource(broken, workDirectory: URL(fileURLWithPath: "/tmp")) { Issue.record("a broken core must not build a new one") }
        if case .success(.build) = Signer.coreSource(fresh, workDirectory: URL(fileURLWithPath: "/tmp")) {} else { Issue.record("no core: build one") }
        if case .success = Signer.coreSource(try Self.decode(delta: #""delta":{"enabled":true,"version":2,"core":null},"#), workDirectory: URL(fileURLWithPath: "/tmp")) {
            Issue.record("no core and no core_path: no v2")
        }

        // A lease round-trips through its own encoding.
        let again = try JSONDecoder().decode(SigningJob.self, from: JSONEncoder().encode(offered))
        #expect(again.delta.core?.manifest == core.manifest && again.delta.corePath == "/c" && again.delta.version == 2)
    }

    @Test func manifestHeaderIsBase64URL() throws {
        let data = Data([0xFB, 0xFF, 0xFE, 0x00])
        #expect(WorkerClient.base64URL(data) == "-__-AA")
    }
}
