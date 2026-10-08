import Foundation
import Testing
@testable import RunnerCore

@Suite struct ZipNamesTests {
    /// A stored (uncompressed) archive like zsign writes: names as raw UTF-8, no UTF-8 flag.
    private func archive(_ names: [String]) -> Data {
        var local: [UInt8] = []
        var central: [UInt8] = []
        func le16(_ value: Int, into bytes: inout [UInt8]) { bytes += [UInt8(value & 0xFF), UInt8(value >> 8 & 0xFF)] }
        func le32(_ value: Int, into bytes: inout [UInt8]) { le16(value & 0xFFFF, into: &bytes); le16(value >> 16, into: &bytes) }
        for name in names {
            let bytes = [UInt8](name.utf8)
            let offset = local.count
            le32(0x0403_4B50, into: &local)
            for value in [20, 0, 0] { le16(value, into: &local) }      // version, flags, method
            for _ in 0..<4 { le32(0, into: &local) }                   // time+date, crc, sizes
            le16(bytes.count, into: &local)
            le16(0, into: &local)                                      // extra length
            local += bytes
            le32(0x0201_4B50, into: &central)
            for value in [20, 20, 0, 0] { le16(value, into: &central) } // made by, needed, flags, method
            for _ in 0..<4 { le32(0, into: &central) }                 // time+date, crc, sizes
            for value in [bytes.count, 0, 0, 0, 0] { le16(value, into: &central) } // name, extra, comment, disk, internal
            le32(0, into: &central)                                    // external attributes
            le32(offset, into: &central)
            central += bytes
        }
        var end: [UInt8] = []
        le32(0x0605_4B50, into: &end)
        for value in [0, 0, names.count, names.count] { le16(value, into: &end) }
        le32(central.count, into: &end)
        le32(local.count, into: &end)
        le16(0, into: &end)
        return Data(local + central + end)
    }

    private func flags(_ data: Data) -> (local: [UInt16], central: [UInt16]) {
        var local: [UInt16] = []
        var central: [UInt16] = []
        let bytes = [UInt8](data)
        for index in 0..<(bytes.count - 4) {
            let signature = UInt32(bytes[index]) | UInt32(bytes[index + 1]) << 8 | UInt32(bytes[index + 2]) << 16 | UInt32(bytes[index + 3]) << 24
            if signature == 0x0403_4B50 { local.append(UInt16(bytes[index + 6]) | UInt16(bytes[index + 7]) << 8) }
            if signature == 0x0201_4B50 { central.append(UInt16(bytes[index + 8]) | UInt16(bytes[index + 9]) << 8) }
        }
        return (local, central)
    }

    @Test func marksOnlyNonASCIINamesAsUTF8() throws {
        let url = URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent("zipnames-\(UUID().uuidString).ipa")
        defer { try? FileManager.default.removeItem(at: url) }
        let original = archive(["Payload/", "Payload/Кинодом.app/", "Payload/Кинодом.app/Info.plist"])
        try original.write(to: url)

        #expect(try ZipNames.markUTF8(url) == 2)

        let patched = try Data(contentsOf: url)
        #expect(patched.count == original.count)
        #expect(flags(patched).local == [0, 0x0800, 0x0800])
        #expect(flags(patched).central == [0, 0x0800, 0x0800])
        // Already marked: nothing more to do.
        #expect(try ZipNames.markUTF8(url) == 0)
    }

    @Test func leavesAnASCIIArchiveUntouched() throws {
        let url = URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent("zipnames-\(UUID().uuidString).ipa")
        defer { try? FileManager.default.removeItem(at: url) }
        let original = archive(["Payload/", "Payload/App.app/Info.plist"])
        try original.write(to: url)

        #expect(try ZipNames.markUTF8(url) == 0)
        #expect(try Data(contentsOf: url) == original)
    }

    @Test func refusesSomethingThatIsNotAnArchive() throws {
        let url = URL(fileURLWithPath: NSTemporaryDirectory()).appendingPathComponent("zipnames-\(UUID().uuidString).ipa")
        defer { try? FileManager.default.removeItem(at: url) }
        try Data("not a zip at all, just text that is long enough".utf8).write(to: url)

        #expect(throws: RunnerError.self) { try ZipNames.markUTF8(url) }
    }
}
