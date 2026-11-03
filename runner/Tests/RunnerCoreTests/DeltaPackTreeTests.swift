import Foundation
import Testing
@testable import RunnerCore

/// Delta v2: packs cut from the signed folder (DeltaPack.buildFromTree). The source IPAs are written
/// by hand like in DeltaPackTests; the end-to-end tests at the bottom sign for real and need zsign.
@Suite struct DeltaPackTreeTests {
    typealias Entry = DeltaPackTests.TestEntry

    /// Writes `files` under `<folder>/tree` and returns the tree root (the folder that holds Payload/).
    static func tree(_ files: [String: Data], in folder: DeltaPackTests.Folder) throws -> URL {
        let root = folder.url.appendingPathComponent("tree", isDirectory: true)
        for (name, content) in files {
            let file = root.appendingPathComponent(name)
            try FileManager.default.createDirectory(at: file.deletingLastPathComponent(), withIntermediateDirectories: true)
            try content.write(to: file)
        }
        return root
    }

    struct Built {
        var folder: DeltaPackTests.Folder
        var source: URL
        var root: URL
        var output: URL
        var outcome: DeltaPack.Outcome
    }

    static func build(source: [Entry], files: [String: Data], prepare: (URL) throws -> Void = { _ in }) throws -> Built {
        let folder = DeltaPackTests.Folder()
        let sourceURL = try folder.write(DeltaPackTests.zip(source), "source.ipa")
        let root = try tree(files, in: folder)
        try prepare(root)
        let output = folder.url.appendingPathComponent("delta.pack")
        let outcome = DeltaPack.buildFromTree(source: sourceURL, treeRoot: root, output: output, baseSHA256: DeltaPackTests.base64)
        return Built(folder: folder, source: sourceURL, root: root, output: output, outcome: outcome)
    }

    static func content(_ text: String) -> Data { Data(text.utf8) }

    /// Source entries and tree files for the classification tests.
    static func scenario(heavy: Int = 200_000, binary: Int = 1_000) -> (source: [Entry], files: [String: Data]) {
        let big = DeltaPackTests.filler(heavy, seed: 1)
        let framework = DeltaPackTests.filler(5_000, seed: 7)
        let source = [
            Entry("Payload/"), Entry("Payload/A.app/"),
            Entry("Payload/A.app/big.bin", big),
            Entry("Payload/A.app/A", DeltaPackTests.filler(binary, seed: 3)),
            Entry("Payload/A.app/Info.plist", content("old")),
            Entry("Payload/A.app/same-size.txt", content("aaaa")),
            Entry("Payload/A.app/keep.txt", content("keep"), method: 0),
            Entry("Payload/A.app/gone.txt", content("gone")),
            Entry("Payload/A.app/bz.bin", content("bzip"), method: 12),
            Entry("Payload/A.app/Frameworks/F.framework/F", framework),
        ]
        let files: [String: Data] = [
            "Payload/A.app/big.bin": big,
            "Payload/A.app/A": DeltaPackTests.filler(binary, seed: 4),
            "Payload/A.app/Info.plist": content("new"),
            "Payload/A.app/embedded.mobileprovision": content("profile"),
            "Payload/A.app/same-size.txt": content("bbbb"),
            "Payload/A.app/keep.txt": content("keep"),
            "Payload/A.app/new.txt": content("new"),
            "Payload/A.app/bz.bin": content("bzip"),
            "Payload/A.app/Frameworks/F.framework/F": framework,
        ]
        return (source, files)
    }

    // MARK: Classification and the pack's shape

    @Test func cutsAStoredPackFromTheTree() throws {
        let (source, files) = Self.scenario()
        let built = try Self.build(source: source, files: files) { root in
            // Empty folders are no entries.
            try FileManager.default.createDirectory(at: root.appendingPathComponent("Payload/A.app/empty"), withIntermediateDirectories: true)
        }
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        let (text, manifestEntry, archive) = try DeltaPackTests.manifest(of: built.output)

        // Sorted by the bytes of the name; no directories; 0 = base, 1 = pack.
        let expected: [(String, Int)] = [
            ("Payload/A.app/A", 1),                                  // changed
            ("Payload/A.app/Frameworks/F.framework/F", 0),           // unchanged, deep
            ("Payload/A.app/Info.plist", 1),                         // mandatory
            ("Payload/A.app/big.bin", 0),
            ("Payload/A.app/bz.bin", 1),                             // the source holds it with method 12
            ("Payload/A.app/embedded.mobileprovision", 1),           // not in the source (and mandatory)
            ("Payload/A.app/keep.txt", 0),                           // stored in the source
            ("Payload/A.app/new.txt", 1),
            ("Payload/A.app/same-size.txt", 1),                      // same size, other content: needs the checksum
        ]
        let actual = try DeltaPackTests.decisions(text)
        #expect(actual.map(\.0) == expected.map(\.0))
        #expect(actual.map(\.1) == expected.map(\.1))
        #expect(text.hasPrefix("{\"version\":1,\"base_sha256\":\"\(DeltaPackTests.base64)\",\"made_by\":0,\"external\":0,\"entries\":[[\"Payload/A.app/A\",1]"))
        #expect(result.entriesTotal == expected.count)
        #expect(result.entriesPack == 6)
        #expect(result.signedBytes == Int64(files.values.reduce(0) { $0 + $1.count }))

        // Stored, flags 0, made by 0, external 0, no descriptor; the manifest is last and stored too.
        #expect(archive.entries.count == result.entriesPack + 1)
        #expect(String(decoding: manifestEntry.name, as: UTF8.self) == DeltaPack.manifestName)
        let packed = Array(archive.entries.dropLast())
        #expect(packed.map { String(decoding: $0.name, as: UTF8.self) } == expected.filter { $0.1 == 1 }.map(\.0))
        for entry in packed + [manifestEntry] {
            #expect(entry.method == 0)
            #expect(entry.flags == 0 || entry.name == manifestEntry.name)
        }
        for entry in packed {
            #expect(entry.madeBy == 0)
            #expect(entry.externalAttributes == 0)
            let name = String(decoding: entry.name, as: UTF8.self)
            let file = try #require(files[name])
            #expect(entry.compressedSize == entry.uncompressedSize)
            #expect(Int(entry.uncompressedSize) == file.count)
            #expect(entry.crc == DeltaPack.crc32(file))
        }
        #expect(result.sha256 == (try RequestSigner.sha256(fileAt: built.output)))
        #expect(result.packBytes == Int64(try Data(contentsOf: built.output).count))
        #expect(result.report["entries_pack"] == "6")
    }

    @Test func spreadsManyFilesOverTheCores() throws {
        // 400 small files (the first 40 changed) and a few big ones: every checksum must land on its own file.
        var source: [Entry] = [Entry("Payload/")]
        var files: [String: Data] = [:]
        for index in 0..<400 {
            let name = String(format: "Payload/A.app/data/f%03d.bin", index)
            source.append(Entry(name, DeltaPackTests.filler(500 + index * 7, seed: UInt8(index % 200))))
            files[name] = DeltaPackTests.filler(500 + index * 7, seed: UInt8(index < 40 ? 250 : index % 200))
        }
        for (index, size) in [3_000_000, 1_500_000, 700_000].enumerated() {
            let name = "Payload/A.app/assets/big\(index).bin"
            source.append(Entry(name, DeltaPackTests.filler(size, seed: UInt8(10 + index))))
            files[name] = DeltaPackTests.filler(size, seed: UInt8(10 + index))
        }
        let built = try Self.build(source: source, files: files)
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        let decisions = try DeltaPackTests.decisions(try DeltaPackTests.manifest(of: built.output).text)
        #expect(decisions.count == 403)
        for (name, from) in decisions {
            let number = Int(name.dropFirst("Payload/A.app/data/f".count).prefix(3)) ?? 999
            #expect(from == (number < 40 ? 1 : 0), "\(name)")
        }
        #expect(result.entriesPack == 40)
    }

    @Test func crc32OfLongBuffersMatchesTheBytewiseDefinition() {
        func reference(_ data: [UInt8]) -> UInt32 {
            var value: UInt32 = 0xFFFF_FFFF
            for byte in data {
                value ^= UInt32(byte)
                for _ in 0..<8 { value = value & 1 == 1 ? 0xEDB8_8320 ^ (value >> 1) : value >> 1 }
            }
            return ~value
        }
        let bytes = [UInt8](DeltaPackTests.filler(100_003, seed: 5))
        for length in [0, 1, 7, 8, 9, 15, 16, 17, 63, 1_000, 100_003] {
            #expect(DeltaPack.crc32(Data(bytes.prefix(length))) == reference(Array(bytes.prefix(length))), "length \(length)")
        }
        // Continuing across chunk borders gives the same value.
        let first = DeltaPack.crc32(Data(bytes.prefix(12_345)))
        #expect(DeltaPack.crc32(Data(bytes.dropFirst(12_345)), crc: first) == reference(bytes))
    }

    // MARK: Fallbacks

    @Test func aSymlinkInTheTreeDisablesDelta() throws {
        let (source, files) = Self.scenario()
        let built = try Self.build(source: source, files: files) { root in
            try FileManager.default.createSymbolicLink(atPath: root.appendingPathComponent("Payload/A.app/link").path, withDestinationPath: "big.bin")
        }
        #expect(built.outcome.result == nil)
        #expect(built.outcome.reason?.contains("not a regular file") == true, "\(built.outcome.reason ?? "")")
        #expect(!FileManager.default.fileExists(atPath: built.output.path))
    }

    @Test func aFolderSymlinkAndAFifoDisableDelta() throws {
        let (source, files) = Self.scenario()
        let folderLink = try Self.build(source: source, files: files) { root in
            try FileManager.default.createSymbolicLink(atPath: root.appendingPathComponent("Payload/A.app/Frameworks/Current").path, withDestinationPath: "F.framework")
        }
        #expect(folderLink.outcome.result == nil)
        let fifo = try Self.build(source: source, files: files) { root in
            #expect(mkfifo(root.appendingPathComponent("Payload/A.app/pipe").path, 0o600) == 0)
        }
        #expect(fifo.outcome.result == nil)
    }

    @Test func aNonASCIIFileNameDisablesDelta() throws {
        var (source, files) = Self.scenario()
        files["Payload/A.app/Данные.txt"] = Self.content("юникод")
        source.append(Entry("Payload/A.app/Данные.txt", Self.content("юникод")))
        let built = try Self.build(source: source, files: files)
        #expect(built.outcome.result == nil)
        #expect(built.outcome.reason?.contains("ASCII") == true, "\(built.outcome.reason ?? "")")
    }

    @Test func otherReasonsForNoDelta() throws {
        // A pack that is not under half of the tree.
        var (source, files) = Self.scenario(heavy: 2_000, binary: 100_000)
        var built = try Self.build(source: source, files: files)
        #expect(built.outcome.result == nil)
        #expect(built.outcome.reason?.contains("half") == true, "\(built.outcome.reason ?? "")")
        #expect(!FileManager.default.fileExists(atPath: built.output.path))

        // An entry already named like the manifest, in the source or in the tree.
        (source, files) = Self.scenario()
        built = try Self.build(source: source + [Entry(DeltaPack.manifestName, Self.content("{}"))], files: files)
        #expect(built.outcome.result == nil)
        files[DeltaPack.manifestName] = Self.content("{}")
        built = try Self.build(source: source, files: files)
        #expect(built.outcome.result == nil)

        // Duplicate names in the source.
        (source, files) = Self.scenario()
        built = try Self.build(source: source + [source[2]], files: files)
        #expect(built.outcome.result == nil)
        #expect(built.outcome.reason?.contains("duplicate") == true)

        // No Payload folder, a source that is not a zip, a tree that is not there.
        let folder = DeltaPackTests.Folder()
        let sourceURL = try folder.write(DeltaPackTests.zip(Self.scenario().source), "source.ipa")
        let empty = folder.url.appendingPathComponent("empty-tree", isDirectory: true)
        try FileManager.default.createDirectory(at: empty, withIntermediateDirectories: true)
        let pack = folder.url.appendingPathComponent("x.pack")
        #expect(DeltaPack.buildFromTree(source: sourceURL, treeRoot: empty, output: pack, baseSHA256: DeltaPackTests.base64).result == nil)
        let notZip = try folder.write(Data("not a zip, but long enough to be read as one".utf8), "bad.ipa")
        let tree = try Self.tree(Self.scenario().files, in: folder)
        #expect(DeltaPack.buildFromTree(source: notZip, treeRoot: tree, output: pack, baseSHA256: DeltaPackTests.base64).result == nil)
        #expect(DeltaPack.buildFromTree(source: sourceURL, treeRoot: folder.url.appendingPathComponent("nowhere"), output: pack, baseSHA256: DeltaPackTests.base64).result == nil)
        #expect(DeltaPack.buildFromTree(source: sourceURL, treeRoot: tree, output: pack, baseSHA256: "nothex").result == nil)
    }

    // MARK: Round trip

    /// Composes the final archive per the spec: base records get the manifest's pair (0, 0).
    @Test(.enabled(if: Tools.available("unzip") && Tools.available("diff")))
    func composedArchiveHoldsExactlyTheTreesFiles() throws {
        let (source, files) = Self.scenario(heavy: 8_000_000, binary: 2_500_000)
        let built = try Self.build(source: source, files: files)
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        #expect(result.packBytes < 3_000_000)

        let text = try DeltaPackTests.manifest(of: built.output).text
        let composed = try DeltaPackTests.compose(manifest: try DeltaPackTests.decisions(text), pair: try DeltaPackTests.pair(text), base: built.source, pack: built.output)
        let final = try built.folder.write(composed, "final.ipa")

        let test = try Shell.run("unzip", ["-t", final.path], in: built.folder.url)
        #expect(test.status == 0, "\(test.output)\(test.error)")
        #expect(test.output.contains("No errors detected"))

        // Every entry, byte for byte, is the tree's file; there is nothing else.
        let finalArchive = try DeltaPack.Archive.read(final, label: "final")
        #expect(finalArchive.entries.map { String(decoding: $0.name, as: UTF8.self) } == files.keys.sorted { Data($0.utf8).lexicographicallyPrecedes(Data($1.utf8)) })
        #expect(finalArchive.entries.allSatisfy { $0.madeBy == 0 && $0.externalAttributes == 0 })
        let extracted = built.folder.url.appendingPathComponent("extracted", isDirectory: true)
        let unzip = try Shell.run("unzip", ["-q", final.path, "-d", extracted.path], in: built.folder.url)
        #expect(unzip.status == 0, "\(unzip.output)\(unzip.error)")
        let diff = try Shell.run("diff", ["-r", extracted.appendingPathComponent("Payload").path, built.root.appendingPathComponent("Payload").path], in: built.folder.url)
        #expect(diff.status == 0, "\(diff.output)")
        #expect(composed.count > Int(result.packBytes) * 2)
    }
}

/// Signing for real (zsign, openssl, unzip, zip): the delta path end to end.
@Suite(.enabled(if: Tools.available("zsign") && Tools.available("openssl") && Tools.available("unzip") && Tools.available("zip")))
struct DeltaSigningTests {
    /// The demo app with a 400 KB resource added, so a pack of the re-signed files is under half.
    static func heavySource(_ setup: SigningSetup) throws -> (url: URL, job: SigningJob) {
        let build = setup.folder.root.appendingPathComponent("heavy-source", isDirectory: true)
        let app = try Signer.unpack(setup.source, into: build, code: "TEST_SETUP")
        try DeltaPackTests.filler(400_000, seed: 21).write(to: app.appendingPathComponent("resource.bin"))
        let url = setup.folder.root.appendingPathComponent("heavy.ipa")
        _ = try Shell.require("TEST_SETUP", "zip", ["-qr", url.path, "Payload"], in: build)
        var job = setup.job
        job.source = .init(sha256: try RequestSigner.sha256(fileAt: url), sizeBytes: 0, path: "/source")
        job.delta = .init(enabled: true)
        return (url, job)
    }

    @Test(arguments: [false, true])
    func buildsAPackFromTheSignedTreeThatComposesIntoAVerifiedApp(cached: Bool) throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let (source, job) = try Self.heavySource(setup)
        let settings = cached ? RunnerCache.Settings(directory: setup.folder.root.appendingPathComponent("cache")) : nil
        let signer = Signer(cache: settings)
        let output = try signer.signDelta(job: job, identity: setup.identity, source: source, workDirectory: setup.work)

        #expect(output.format == DeltaPack.format, "\(output.report["delta_fallback"] ?? "")")
        #expect(output.ipa.lastPathComponent == "delta.pack")
        #expect(!FileManager.default.fileExists(atPath: setup.work.appendingPathComponent("signed.ipa").path), "no IPA is written")
        #expect(output.sha256 == (try RequestSigner.sha256(fileAt: output.ipa)))
        for key in ["signer", "zsign_seconds", "verification_seconds", "delta_seconds", "pack_bytes", "entries_total", "entries_pack", "codesign", "profile_uuid", "workspace_cache"] {
            #expect(output.report[key] != nil, "\(key)")
        }
        #expect(output.report["delta_fallback"] == nil)

        // Compose per the spec, unpack it and verify it like an uploaded IPA.
        let text = try DeltaPackTests.manifest(of: output.ipa).text
        let composed = try DeltaPackTests.compose(manifest: try DeltaPackTests.decisions(text), pair: try DeltaPackTests.pair(text), base: source, pack: output.ipa)
        let final = setup.folder.root.appendingPathComponent("final.ipa")
        try composed.write(to: final)
        let app = try Signer.unpack(final, into: setup.folder.root.appendingPathComponent("final-check", isDirectory: true), code: "TEST_SETUP")
        _ = try Signer.verify(app: app, job: job, profile: setup.profile, workDirectory: setup.work)
        #expect(try Data(contentsOf: app.appendingPathComponent("resource.bin")) == DeltaPackTests.filler(400_000, seed: 21))
        // resource.bin comes from the base: it is not in the pack.
        #expect(try DeltaPackTests.decisions(text).contains { $0.0.hasSuffix("/resource.bin") && $0.1 == 0 })
    }

    @Test(arguments: [false, true])
    func fallsBackToTheFullIPAWhenThePackIsNotWorthIt(cached: Bool) throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        // The plain demo app: nearly all of it changes when it is signed.
        var job = setup.job
        job.delta = .init(enabled: true)
        let settings = cached ? RunnerCache.Settings(directory: setup.folder.root.appendingPathComponent("cache")) : nil
        let signer = Signer(cache: settings)
        let output = try signer.signDelta(job: job, identity: setup.identity, source: setup.source, workDirectory: setup.work)

        #expect(output.format == nil)
        #expect(output.ipa.lastPathComponent == "signed.ipa")
        #expect(output.report["delta_fallback"]?.contains("half") == true, "\(output.report["delta_fallback"] ?? "none")")
        #expect(output.report["zip_level"] != nil)
        #expect(output.sha256 == (try RequestSigner.sha256(fileAt: output.ipa)))
        // A complete, verified IPA, as the usual path makes it.
        let app = try Signer.unpack(output.ipa, into: setup.folder.root.appendingPathComponent("fallback-check", isDirectory: true), code: "TEST_SETUP")
        _ = try Signer.verify(app: app, job: job, profile: setup.profile, workDirectory: setup.work)
        #expect(!FileManager.default.fileExists(atPath: setup.work.appendingPathComponent("delta.pack").path))
    }
}
