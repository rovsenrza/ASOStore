import Foundation
import Testing
@testable import RunnerCore

/// Zips are written by hand here (deflate "stored" blocks, so no zip tool is needed); only the
/// round-trip test uses `unzip` to read the composed archive back.
@Suite struct DeltaPackTests {
    // MARK: Fixtures

    struct TestEntry {
        var name: Data
        var content: Data
        var method: UInt16 = 8
        var flags: UInt16 = 0
        var descriptor = false
        var attributes: UInt32 = 0o100644 << 16
        /// "Version made by": made on Unix, like the source IPAs (zsign writes 0).
        var madeBy: UInt16 = 3 << 8 | 20
        var extra = Data()
        var crc: UInt32

        init(_ name: String, _ content: Data = Data(), method: UInt16 = 8, flags: UInt16 = 0, descriptor: Bool = false,
             attributes: UInt32 = 0o100644 << 16, madeBy: UInt16 = 3 << 8 | 20, extra: Data = Data()) {
            self.madeBy = madeBy
            self.name = Data(name.utf8)
            self.content = content
            self.method = name.hasSuffix("/") ? 0 : method
            self.flags = flags
            self.descriptor = descriptor
            self.attributes = name.hasSuffix("/") ? 0o040755 << 16 : attributes
            self.extra = extra
            self.crc = DeltaPack.crc32(content)
        }

        /// Deflate as one or more final-flagged "stored" blocks: valid for any inflater.
        var payload: Data {
            guard method == 8 else { return content }
            var out = Data()
            var offset = 0
            repeat {
                let count = min(65_535, content.count - offset)
                let last = offset + count >= content.count
                out.append(last ? 1 : 0)
                out.append(contentsOf: [UInt8(count & 0xFF), UInt8(count >> 8), UInt8(~count & 0xFF), UInt8((~count >> 8) & 0xFF)])
                out.append(content[offset ..< offset + count])
                offset += count
            } while offset < content.count
            return out
        }
    }

    static func le16(_ value: Int) -> [UInt8] { [UInt8(value & 0xFF), UInt8(value >> 8 & 0xFF)] }
    static func le32(_ value: Int) -> [UInt8] { le16(value & 0xFFFF) + le16(value >> 16 & 0xFFFF) }

    static func zip(_ entries: [TestEntry], comment: String = "", zip64Locator: Bool = false) -> Data {
        var out = Data()
        var central = Data()
        for entry in entries {
            let payload = entry.payload
            let ascii = !entry.name.contains(where: { $0 >= 0x80 })
            let flags = Int(entry.flags) | (entry.descriptor ? 8 : 0) | (ascii ? 0 : 0x800)
            let offset = out.count
            out.append(contentsOf: le32(0x0403_4B50) + le16(20) + le16(flags) + le16(Int(entry.method)) + le16(0) + le16(0x21))
            out.append(contentsOf: entry.descriptor ? le32(0) + le32(0) + le32(0) : le32(Int(entry.crc)) + le32(payload.count) + le32(entry.content.count))
            out.append(contentsOf: le16(entry.name.count) + le16(entry.extra.count))
            out.append(entry.name); out.append(entry.extra); out.append(payload)
            if entry.descriptor { out.append(contentsOf: le32(0x0807_4B50) + le32(Int(entry.crc)) + le32(payload.count) + le32(entry.content.count)) }
            central.append(contentsOf: le32(0x0201_4B50) + le16(Int(entry.madeBy)) + le16(20) + le16(flags) + le16(Int(entry.method)) + le16(0) + le16(0x21))
            central.append(contentsOf: le32(Int(entry.crc)) + le32(payload.count) + le32(entry.content.count))
            central.append(contentsOf: le16(entry.name.count) + le16(entry.extra.count) + le16(0) + le16(0) + le16(0))
            central.append(contentsOf: le32(Int(entry.attributes)) + le32(offset))
            central.append(entry.name); central.append(entry.extra)
        }
        let directoryOffset = out.count
        out.append(central)
        if zip64Locator { out.append(contentsOf: le32(0x0706_4B50) + [UInt8](repeating: 0, count: 16)) }
        out.append(contentsOf: le32(0x0605_4B50) + le16(0) + le16(0) + le16(entries.count) + le16(entries.count))
        out.append(contentsOf: le32(central.count) + le32(directoryOffset) + le16(comment.utf8.count))
        out.append(Data(comment.utf8))
        return out
    }

    /// Deterministic filler that does not compress to nothing and differs per seed.
    static func filler(_ count: Int, seed: UInt8) -> Data {
        var state = UInt32(seed) &* 2_654_435_761 | 1
        return Data((0..<count).map { _ in state = state &* 1_664_525 &+ 1_013_904_223; return UInt8(state >> 24) })
    }

    final class Folder {
        let url = URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent("delta-\(UUID().uuidString)", isDirectory: true)
        init() { try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true) }
        deinit { try? FileManager.default.removeItem(at: url) }
        func write(_ data: Data, _ name: String) throws -> URL {
            let file = url.appendingPathComponent(name)
            try data.write(to: file)
            return file
        }
    }

    static let base64 = String(repeating: "ab", count: 32)

    /// The same app before and after signing. `heavy` is the unchanged payload that makes a pack worth it.
    struct Scenario {
        var source: [TestEntry]
        var signed: [TestEntry]
    }

    static func scenario(heavy: Int = 200_000, binary: Int = 1_000) -> Scenario {
        let big = TestEntry("Payload/A.app/big.bin", filler(heavy, seed: 1))
        let plist = TestEntry("Payload/A.app/Info.plist", Data("<plist>old</plist>".utf8))
        let profile = TestEntry("Payload/A.app/embedded.mobileprovision", Data("profile".utf8))
        let seal = TestEntry("Payload/A.app/_CodeSignature/CodeResources", Data("seal".utf8))
        let deep = TestEntry("Payload/A.app/Frameworks/X.framework/Info.plist", Data("framework".utf8))
        let same = [
            TestEntry("Payload/"), TestEntry("Payload/A.app/"),
            big, plist, profile, seal, deep,
            TestEntry("Info.plist", Data("top level".utf8)),
            TestEntry("Payload/A.app/dd.bin", filler(3_000, seed: 2), descriptor: true),
            TestEntry("Payload/A.app/Данные.txt", Data("юникод".utf8)),
            TestEntry("Payload/A.app/plain.txt", Data("same".utf8), method: 0),
        ]
        let binaryBefore = TestEntry("Payload/A.app/A", filler(binary, seed: 3))
        let binaryAfter = TestEntry("Payload/A.app/A", filler(binary, seed: 4))
        let scriptBefore = TestEntry("Payload/A.app/run.sh", Data("echo".utf8), attributes: 0o100644 << 16)
        let scriptAfter = TestEntry("Payload/A.app/run.sh", Data("echo".utf8), attributes: 0o100755 << 16)
        let gone = TestEntry("Payload/A.app/gone.txt", Data("gone".utf8))
        let fresh = TestEntry("Payload/A.app/new.txt", Data("new".utf8))
        let encrypted = TestEntry("Payload/A.app/enc.bin", Data("enc".utf8), flags: 1)
        let zip64 = TestEntry("Payload/A.app/z.bin", Data("zip64".utf8), extra: Data(le16(1) + le16(0)))
        let bzip = TestEntry("Payload/A.app/bz.bin", Data("bzip".utf8), method: 12)
        let encryptedAfter = TestEntry("Payload/A.app/enc.bin", Data("enc".utf8))
        let zip64After = TestEntry("Payload/A.app/z.bin", Data("zip64".utf8))
        let bzipAfter = TestEntry("Payload/A.app/bz.bin", Data("bzip".utf8))
        // The signed archive lists its entries in another order, as zsign's repack does.
        let signedPlist = TestEntry("Payload/A.app/Info.plist", Data("<plist>new</plist>".utf8))
        let signed = [
            TestEntry("Payload/"), TestEntry("Payload/A.app/"), binaryAfter, signedPlist, profile, seal, deep,
            big, TestEntry("Info.plist", Data("top level".utf8)), same[8], same[9], same[10],
            scriptAfter, fresh, encryptedAfter, zip64After, bzipAfter,
        ]
        // What zsign does (the real case): every signed record has version made by 0 and external
        // attributes 0, except the one that is meant to differ; the source has Unix modes and data
        // descriptors.
        let zeroed = signed.map { entry -> TestEntry in
            var entry = entry
            // ZipNames.markUTF8 re-marks non-ASCII names as Unix-made, 0755 (unzip would misread them otherwise).
            if entry.name.contains(where: { $0 >= 0x80 }) { entry.attributes = 0o100755 << 16 } else if entry.name != scriptAfter.name { entry.madeBy = 0; entry.attributes = 0 }
            return entry
        }
        let described = (same.filter { $0.name != plist.name } + [plist, binaryBefore, scriptBefore, gone, encrypted, zip64, bzip]).map { entry -> TestEntry in
            var entry = entry
            if entry.method == 8 { entry.descriptor = true }
            return entry
        }
        return Scenario(source: described, signed: zeroed)
    }

    struct Built {
        var folder: Folder
        var source: URL
        var signed: URL
        var output: URL
        var outcome: DeltaPack.Outcome
    }

    static func build(_ scenario: Scenario) throws -> Built {
        let folder = Folder()
        return try build(source: zip(scenario.source), signed: zip(scenario.signed), folder: folder)
    }

    static func build(source: Data, signed: Data, folder: Folder = Folder()) throws -> Built {
        let sourceURL = try folder.write(source, "source.ipa")
        let signedURL = try folder.write(signed, "signed.ipa")
        let output = folder.url.appendingPathComponent("delta.pack")
        let outcome = DeltaPack.build(source: sourceURL, signed: signedURL, output: output, baseSHA256: base64)
        return Built(folder: folder, source: sourceURL, signed: signedURL, output: output, outcome: outcome)
    }

    /// The text of the pack's last (manifest) entry.
    static func manifest(of pack: URL) throws -> (text: String, entry: DeltaPack.Archive.Entry, archive: DeltaPack.Archive) {
        let data = try Data(contentsOf: pack)
        let archive = try DeltaPack.Archive.read(pack, label: "pack")
        let entry = try #require(archive.entries.last)
        let start = Int(entry.localOffset) + 30 + Int(data.le16(Int(entry.localOffset) + 26)) + Int(data.le16(Int(entry.localOffset) + 28))
        return (String(decoding: data[start ..< start + Int(entry.compressedSize)], as: UTF8.self), entry, archive)
    }

    static func pair(_ text: String) throws -> (madeBy: Int, external: Int) {
        let json = try #require(try JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any])
        return (try #require(json["made_by"] as? Int), try #require(json["external"] as? Int))
    }

    static func decisions(_ text: String) throws -> [(String, Int)] {
        let json = try #require(try JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any])
        let entries = try #require(json["entries"] as? [[Any]])
        return entries.map { ($0[0] as! String, $0[1] as! Int) }
    }

    // MARK: Classification and manifest

    @Test func crc32MatchesTheStandardCheckValue() {
        #expect(DeltaPack.crc32(Data("123456789".utf8)) == 0xCBF4_3926)
        #expect(DeltaPack.crc32(Data()) == 0)
        let split = DeltaPack.crc32(Data("6789".utf8), crc: DeltaPack.crc32(Data("12345".utf8)))
        #expect(split == 0xCBF4_3926)
    }

    @Test func classifiesEveryKindOfEntry() throws {
        let built = try Self.build(Self.scenario())
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        let (text, _, _) = try Self.manifest(of: built.output)

        // Final order is the signed archive's; 0 = base, 1 = pack.
        let expected: [(String, Int)] = [
            ("Payload/", 0), ("Payload/A.app/", 0),
            ("Payload/A.app/A", 1),                                  // changed CRC
            ("Payload/A.app/Info.plist", 1),                         // changed and mandatory
            ("Payload/A.app/embedded.mobileprovision", 1),           // identical but mandatory
            ("Payload/A.app/_CodeSignature/CodeResources", 1),       // identical but mandatory
            ("Payload/A.app/Frameworks/X.framework/Info.plist", 1),  // mandatory at any depth
            ("Payload/A.app/big.bin", 0),
            ("Info.plist", 0),                                       // not inside Payload/: no rule applies
            ("Payload/A.app/dd.bin", 0),                             // data descriptor travels with the entry
            ("Payload/A.app/Данные.txt", 1),                         // non-ASCII name
            ("Payload/A.app/plain.txt", 0),                          // stored entry
            ("Payload/A.app/run.sh", 1),                             // its own record pair differs from the archive's
            ("Payload/A.app/new.txt", 1),                            // not in the source
            ("Payload/A.app/enc.bin", 1),                            // encrypted in the source
            ("Payload/A.app/z.bin", 1),                              // zip64 extra field in the source
            ("Payload/A.app/bz.bin", 1),                             // method 12 in the source
        ]
        let actual = try Self.decisions(text)
        #expect(actual.map(\.0) == expected.map(\.0))
        #expect(actual.map(\.1) == expected.map(\.1))
        #expect(!actual.contains { $0.0 == DeltaPack.manifestName })
        #expect(text.hasPrefix("{\"version\":1,\"base_sha256\":\"\(Self.base64)\",\"made_by\":0,\"external\":0,\"entries\":[[\"Payload/\",0],[\"Payload/A.app/\",0],"))
        #expect(text.hasSuffix("[\"Payload/A.app/bz.bin\",1]]}"))
        #expect(result.entriesTotal == expected.count)
        #expect(result.entriesPack == expected.filter { $0.1 == 1 }.count)
    }

    @Test func packIsAnOrdinaryZipWithTheManifestLastAndStored() throws {
        let built = try Self.build(Self.scenario())
        let result = try #require(built.outcome.result)
        let (text, entry, archive) = try Self.manifest(of: built.output)

        #expect(String(decoding: entry.name, as: UTF8.self) == DeltaPack.manifestName)
        #expect(entry.method == 0)
        #expect(entry.flags == 0)
        #expect(entry.crc == DeltaPack.crc32(Data(text.utf8)))
        #expect(archive.entries.count == result.entriesPack + 1)
        // Pack entries keep the signed archive's order and are copied raw.
        let names = archive.entries.dropLast().map { String(decoding: $0.name, as: UTF8.self) }
        #expect(names == (try Self.decisions(text)).filter { $0.1 == 1 }.map(\.0))
        #expect(result.packBytes == Int64(try Data(contentsOf: built.output).count))
        #expect(result.signedBytes == Int64(try Data(contentsOf: built.signed).count))
        #expect(result.sha256 == (try RequestSigner.sha256(fileAt: built.output)))
        #expect(result.baseSHA256 == Self.base64)
        #expect(result.report["entries_pack"] == String(result.entriesPack))
        #expect(result.report["pack_bytes"] == String(result.packBytes))
        #expect(result.report["entries_total"] == String(result.entriesTotal))
        #expect(result.report["delta_seconds"] != nil)
    }

    @Test func escapesNamesInTheManifest() throws {
        var scenario = Self.scenario()
        let odd = TestEntry("Payload/A.app/we\"ird\\name\u{1}.txt", Data("x".utf8))
        var zeroed = odd
        zeroed.madeBy = 0; zeroed.attributes = 0
        scenario.source.append(odd)
        scenario.signed.append(zeroed)
        let built = try Self.build(scenario)
        let (text, _, _) = try Self.manifest(of: built.output)
        #expect(text.contains("[\"Payload/A.app/we\\\"ird\\\\name\\u0001.txt\",0]"))
        #expect(try Self.decisions(text).contains { $0.0 == "Payload/A.app/we\"ird\\name\u{1}.txt" })
    }

    @Test func readsAnArchiveWithATrailingComment() throws {
        let scenario = Self.scenario()
        let built = try Self.build(source: Self.zip(scenario.source, comment: "made by someone"), signed: Self.zip(scenario.signed, comment: "PK\u{5}\u{6} in a comment"))
        #expect(built.outcome.result != nil, "\(built.outcome.reason ?? "")")
    }

    @Test func zsignZerosAgainstUnixSourceStillUseTheBase() throws {
        // The real case: the source carries Unix modes, data descriptors and UTF-8 flags; zsign
        // writes version made by 0, external attributes 0 and flags 0 for every entry.
        let big = TestEntry("Payload/A.app/big.bin", Self.filler(100_000, seed: 1), descriptor: true)
        let changed = TestEntry("Payload/A.app/A", Self.filler(500, seed: 2))
        var zeroBig = big, zeroDir = TestEntry("Payload/")
        zeroBig.madeBy = 0; zeroBig.attributes = 0; zeroBig.descriptor = false
        zeroDir.madeBy = 0; zeroDir.attributes = 0
        var zeroChanged = TestEntry("Payload/A.app/A", Self.filler(500, seed: 3))
        zeroChanged.madeBy = 0; zeroChanged.attributes = 0
        let built = try Self.build(source: Self.zip([TestEntry("Payload/"), big, changed]), signed: Self.zip([zeroDir, zeroBig, zeroChanged]))
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        let (text, _, _) = try Self.manifest(of: built.output)
        #expect(text == "{\"version\":1,\"base_sha256\":\"\(Self.base64)\",\"made_by\":0,\"external\":0,\"entries\":[[\"Payload/\",0],[\"Payload/A.app/big.bin\",0],[\"Payload/A.app/A\",1]]}")
        #expect(result.entriesPack == 1)
    }

    @Test func thePairComesFromTheFirstNonDirectoryEntry() throws {
        // A directory first, with attributes of its own: it does not set the pair.
        var dir = TestEntry("Payload/")
        dir.madeBy = 0; dir.attributes = 0o040700 << 16
        let big = TestEntry("Payload/A.app/big.bin", Self.filler(100_000, seed: 1))
        let other = TestEntry("Payload/A.app/o.txt", Data("o".utf8))
        var otherSigned = other
        otherSigned.madeBy = 0; otherSigned.attributes = 0
        let built = try Self.build(source: Self.zip([TestEntry("Payload/"), big, other]), signed: Self.zip([dir, big, otherSigned]))
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        let (text, _, _) = try Self.manifest(of: built.output)
        let pair = try Self.pair(text)
        #expect(pair.madeBy == 3 << 8 | 20)
        #expect(pair.external == Int(0o100644 << 16))
        // The directory and the zeroed file carry another pair than the archive's: both travel in the pack.
        #expect(try Self.decisions(text).map(\.1) == [1, 0, 1])
        #expect(result.entriesPack == 2)
    }

    @Test func anEntryWithAnotherPairThanTheArchiveGoesToThePack() throws {
        let big = TestEntry("Payload/A.app/big.bin", Self.filler(100_000, seed: 1))
        let a = TestEntry("Payload/A.app/a.txt", Data("a".utf8))
        let b = TestEntry("Payload/A.app/b.txt", Data("b".utf8))
        var madeByDiffers = a, externalDiffers = b
        madeByDiffers.madeBy = 0
        externalDiffers.attributes = 0o120755 << 16 // a symlink, say
        // Identical content in the source; only the signed record's own pair differs.
        let built = try Self.build(source: Self.zip([big, a, b]), signed: Self.zip([big, madeByDiffers, externalDiffers]))
        let (text, _, _) = try Self.manifest(of: built.output)
        #expect(try Self.decisions(text).map(\.1) == [0, 1, 1])
        #expect(try Self.pair(text).madeBy == 3 << 8 | 20)
    }

    @Test func aSignedArchiveOfDirectoriesOnlyDisablesDelta() throws {
        let built = try Self.build(source: Self.zip([TestEntry("Payload/"), TestEntry("Payload/A.app/")]), signed: Self.zip([TestEntry("Payload/"), TestEntry("Payload/A.app/")]))
        #expect(built.outcome.result == nil)
    }

    // MARK: Fallbacks

    @Test func duplicateNamesDisableDelta() throws {
        let scenario = Self.scenario()
        let duplicated = scenario.signed + [scenario.signed[3]]
        let inSigned = try Self.build(source: Self.zip(scenario.source), signed: Self.zip(duplicated))
        #expect(inSigned.outcome.result == nil)
        #expect(inSigned.outcome.reason?.contains("duplicate") == true)
        #expect(!FileManager.default.fileExists(atPath: inSigned.output.path))

        let inSource = try Self.build(source: Self.zip(scenario.source + [scenario.source[3]]), signed: Self.zip(scenario.signed))
        #expect(inSource.outcome.result == nil)
        #expect(inSource.outcome.reason?.contains("duplicate") == true)
    }

    @Test func anEntryNamedLikeTheManifestDisablesDelta() throws {
        var scenario = Self.scenario()
        scenario.signed.append(TestEntry(DeltaPack.manifestName, Data("{}".utf8)))
        #expect(try Self.build(scenario).outcome.result == nil)

        scenario = Self.scenario()
        scenario.source.append(TestEntry(DeltaPack.manifestName, Data("{}".utf8)))
        #expect(try Self.build(scenario).outcome.result == nil)
    }

    @Test func zip64DisablesDelta() throws {
        let scenario = Self.scenario()
        let plain = Self.zip(scenario.signed)
        let end = try #require(stride(from: plain.count - 22, through: 0, by: -1).first { plain.le32($0) == 0x0605_4B50 })

        func patched(_ edit: (inout Data, Int) -> Void) -> Data {
            var data = plain
            edit(&data, end)
            return data
        }
        let cases: [(String, Data)] = [
            ("entry count marker", patched { $0.setLE16(0xFFFF, at: $1 + 10) }),
            ("directory size marker", patched { $0.setLE32(0xFFFF_FFFF, at: $1 + 12) }),
            ("directory offset marker", patched { $0.setLE32(0xFFFF_FFFF, at: $1 + 16) }),
            ("zip64 locator", Self.zip(scenario.signed, zip64Locator: true)),
        ]
        for (label, data) in cases {
            for asSource in [false, true] {
                let built = asSource
                    ? try Self.build(source: data, signed: plain)
                    : try Self.build(source: Self.zip(scenario.source), signed: data)
                #expect(built.outcome.result == nil, "\(label) as \(asSource ? "source" : "signed")")
                #expect(built.outcome.reason?.contains("zip64") == true, "\(label): \(built.outcome.reason ?? "")")
            }
        }

        // A zip64 marker in an entry of the central directory (sizes at their maximum).
        var entrySizes = plain
        let firstRecord = try #require(entrySizes.indices.first { entrySizes.le32($0) == 0x0201_4B50 })
        entrySizes.setLE32(0xFFFF_FFFF, at: firstRecord + 24)
        #expect(try Self.build(source: Self.zip(scenario.source), signed: entrySizes).outcome.result == nil)

        // A zip64 extra field in the signed archive's own entry.
        var withExtra = scenario.signed
        withExtra[2].extra = Data(Self.le16(1) + Self.le16(0))
        #expect(try Self.build(source: Self.zip(scenario.source), signed: Self.zip(withExtra)).outcome.result == nil)
    }

    @Test func garbageDisablesDelta() throws {
        let scenario = Self.scenario()
        let notZip = Data("this is not a zip file, it is just text that is long enough".utf8)
        #expect(try Self.build(source: notZip, signed: Self.zip(scenario.signed)).outcome.result == nil)
        #expect(try Self.build(source: Self.zip(scenario.source), signed: notZip).outcome.result == nil)
        let truncated = Self.zip(scenario.signed).dropLast(40)
        #expect(try Self.build(source: Self.zip(scenario.source), signed: Data(truncated)).outcome.result == nil)
        // A missing file is a fallback too, not a crash.
        let outcome = DeltaPack.build(source: URL(fileURLWithPath: "/nonexistent/source.ipa"), signed: URL(fileURLWithPath: "/nonexistent/signed.ipa"),
                                      output: URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent("never.pack"), baseSHA256: Self.base64)
        #expect(outcome.result == nil)
    }

    @Test func aPackThatIsNotUnderHalfIsNotUsed() throws {
        // Nothing in common with the source: everything would travel in the pack.
        var scenario = Self.scenario()
        scenario.source = [TestEntry("Payload/Other.app/x.bin", Self.filler(5_000, seed: 9))]
        let built = try Self.build(scenario)
        #expect(built.outcome.result == nil)
        #expect(built.outcome.reason?.contains("half") == true)
        #expect(!FileManager.default.fileExists(atPath: built.output.path))

        // The boundary: a changed entry bigger than the rest is too much, a small one is fine.
        for (changed, usable) in [(120_000, false), (20_000, true)] {
            let source = [TestEntry("Payload/A.app/big.bin", Self.filler(100_000, seed: 1)), TestEntry("Payload/A.app/A", Self.filler(changed, seed: 5))]
            let signed = [TestEntry("Payload/A.app/big.bin", Self.filler(100_000, seed: 1)), TestEntry("Payload/A.app/A", Self.filler(changed, seed: 6))]
            let outcome = try Self.build(source: Self.zip(source), signed: Self.zip(signed)).outcome
            #expect((outcome.result != nil) == usable, "changed \(changed): \(outcome.reason ?? "pack")")
        }
    }

    @Test func nonUTF8NamesDisableDelta() throws {
        var scenario = Self.scenario()
        var odd = TestEntry("Payload/A.app/x", Data("x".utf8))
        odd.name = Data([0x50, 0x61, 0x79, 0xFF, 0xFE])
        scenario.signed.append(odd)
        let built = try Self.build(scenario)
        #expect(built.outcome.result == nil)
        #expect(built.outcome.reason?.contains("UTF-8") == true)
    }

    // MARK: Lease

    @Test func leaseDeltaDefaultsToOff() throws {
        let base = #"{"job_id":"j","signed_build_id":"b","bundle_identifier":"a.b","team_identifier":"T","certificate_sha1":"AA","profile":{"uuid":"u","content":"c"},"source":{"sha256":"h","size_bytes":1,"path":"/s"},"upload_path":"/u","result_path":"/r"#
        let without = try JSONDecoder().decode(SigningJob.self, from: Data((base + "\"}").utf8))
        #expect(without.delta.enabled == false)
        let on = try JSONDecoder().decode(SigningJob.self, from: Data((base + "\",\"delta\":{\"enabled\":true}}").utf8))
        #expect(on.delta.enabled)
        let empty = try JSONDecoder().decode(SigningJob.self, from: Data((base + "\",\"delta\":{}}").utf8))
        #expect(empty.delta.enabled == false)
    }

    // MARK: Round trip

    /// Composes the final archive the way the spec's "Composition" section says, from the base and the pack.
    static func compose(manifest: [(String, Int)], pair: (madeBy: Int, external: Int), base: URL, pack: URL) throws -> Data {
        let sources = [0: (try Data(contentsOf: base), try DeltaPack.Archive.read(base, label: "base")),
                       1: (try Data(contentsOf: pack), try DeltaPack.Archive.read(pack, label: "pack"))]
        var out = Data()
        var directory = Data()
        for (name, from) in manifest {
            let (data, archive) = try #require(sources[from])
            let entry = try #require(archive.entries.first { $0.name == Data(name.utf8) })
            // Raw extent: up to the next larger local header offset, or the start of the central directory.
            let end = archive.entries.map { UInt64($0.localOffset) }.filter { $0 > UInt64(entry.localOffset) }.min() ?? archive.directoryOffset
            var record = entry.record
            record.setLE32(UInt32(out.count), at: 42)
            if from == 0 {
                record.setLE16(UInt16(pair.madeBy), at: 4)      // version made by
                record.setLE32(UInt32(pair.external), at: 38)   // external attributes
            }
            directory.append(record)
            out.append(data[Int(entry.localOffset) ..< Int(end)])
        }
        let directoryOffset = out.count
        out.append(directory)
        out.append(contentsOf: Self.le32(0x0605_4B50) + Self.le16(0) + Self.le16(0) + Self.le16(manifest.count) + Self.le16(manifest.count))
        out.append(contentsOf: Self.le32(directory.count) + Self.le32(directoryOffset) + Self.le16(0))
        return out
    }

    @Test(.enabled(if: Tools.available("unzip") && Tools.available("diff")))
    func composedArchiveHoldsTheSignedContent() throws {
        // 8 MB unchanged and a 2.5 MB changed binary: copying spans several 1 MB chunks.
        let built = try Self.build(Self.scenario(heavy: 8_000_000, binary: 2_500_000))
        let result = try #require(built.outcome.result, "fallback: \(built.outcome.reason ?? "")")
        #expect(result.packBytes < 3_000_000)

        let (text, _, _) = try Self.manifest(of: built.output)
        let manifest = try Self.decisions(text)
        let composed = try Self.compose(manifest: manifest, pair: try Self.pair(text), base: built.source, pack: built.output)
        let final = try built.folder.write(composed, "final.ipa")

        let test = try Shell.run("unzip", ["-t", final.path], in: built.folder.url)
        #expect(test.status == 0, "\(test.output)\(test.error)")
        #expect(test.output.contains("No errors detected"))

        // Same entries in the same order as the signed archive, and the same content in every one.
        let signedArchive = try DeltaPack.Archive.read(built.signed, label: "signed")
        let finalArchive = try DeltaPack.Archive.read(final, label: "final")
        #expect(finalArchive.entries.map(\.name) == signedArchive.entries.map(\.name))
        #expect(finalArchive.entries.map(\.crc) == signedArchive.entries.map(\.crc))
        #expect(finalArchive.entries.map(\.uncompressedSize) == signedArchive.entries.map(\.uncompressedSize))
        // The record pair is the signed archive's (zeros) for base entries too; pack entries keep their own.
        #expect(finalArchive.entries.map(\.externalAttributes) == signedArchive.entries.map(\.externalAttributes))
        #expect(finalArchive.entries.map(\.madeBy) == signedArchive.entries.map(\.madeBy))
        // Base entries keep the source's local headers: it has data descriptors, the signed zip has some of its own.
        #expect(finalArchive.entries.map(\.flags) != signedArchive.entries.map(\.flags))

        let signedTree = built.folder.url.appendingPathComponent("signed-tree", isDirectory: true)
        let finalTree = built.folder.url.appendingPathComponent("final-tree", isDirectory: true)
        for (archive, tree) in [(built.signed, signedTree), (final, finalTree)] {
            let extracted = try Shell.run("unzip", ["-q", archive.path, "-d", tree.path], in: built.folder.url)
            #expect(extracted.status == 0, "\(extracted.output)\(extracted.error)")
        }
        let diff = try Shell.run("diff", ["-r", signedTree.path, finalTree.path], in: built.folder.url)
        #expect(diff.status == 0, "\(diff.output)")
        // The base really supplied the heavy entry: the final archive is much bigger than the pack.
        #expect(composed.count > Int(result.packBytes) * 2)
    }
}
