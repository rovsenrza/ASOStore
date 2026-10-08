import Foundation

/// zsign writes entry names as raw UTF-8 bytes but marks every entry as made on MS-DOS and
/// without the zip "language encoding" flag (general purpose bit 11). unzip then converts a
/// non-ASCII name from code page 437, whatever the flag says for a DOS entry: Payload/Кинодом.app
/// came out as invalid UTF-8 and the signed app could not be checked. Each such entry is
/// re-marked the way Apple's own IPAs are: made on Unix, UTF-8 flag, Unix mode bits (0755,
/// so the executable stays executable). Sizes, offsets and CRCs stay the same.
enum ZipNames {
    static let utf8Flag: UInt16 = 0x0800

    /// Marks non-ASCII UTF-8 names in place and returns how many entries changed. A ZIP64
    /// archive is left alone (zsign does not write one for app-sized archives).
    @discardableResult
    static func markUTF8(_ url: URL) throws -> Int {
        let handle = try FileHandle(forUpdating: url)
        defer { try? handle.close() }

        let size = try handle.seekToEnd()
        let tailLength = min(size, 65_557) // end-of-central-directory record + longest comment
        try handle.seek(toOffset: size - tailLength)
        let tail = try handle.readToEnd() ?? Data()
        guard let end = lastSignature(0x0605_4B50, in: tail) else {
            throw RunnerError.job(code: "REPACK_FAILED", message: "Signed archive has no end of central directory", retryable: false)
        }
        let entries = tail.le16(end + 10)
        let directorySize = tail.le32(end + 12)
        let directoryOffset = tail.le32(end + 16)
        if entries == 0xFFFF || directorySize == 0xFFFF_FFFF || directoryOffset == 0xFFFF_FFFF {
            return 0
        }

        try handle.seek(toOffset: UInt64(directoryOffset))
        var directory = try handle.read(upToCount: Int(directorySize)) ?? Data()
        var position = 0
        var locals: [UInt64] = []
        for _ in 0..<entries {
            guard position + 46 <= directory.count, directory.le32(position) == 0x0201_4B50 else {
                throw RunnerError.job(code: "REPACK_FAILED", message: "Signed archive has a broken central directory", retryable: false)
            }
            let madeBy = directory.le16(position + 4)
            let flags = directory.le16(position + 8)
            let attributes = directory.le32(position + 38)
            let nameLength = Int(directory.le16(position + 28))
            let next = position + 46 + nameLength + Int(directory.le16(position + 30)) + Int(directory.le16(position + 32))
            let localOffset = directory.le32(position + 42)
            let name = directory.subdata(in: position + 46 ..< position + 46 + nameLength)
            if flags & utf8Flag == 0, name.contains(where: { $0 >= 0x80 }), String(data: name, encoding: .utf8) != nil, localOffset != 0xFFFF_FFFF {
                let version = madeBy & 0xFF == 0 ? 20 : madeBy & 0xFF
                directory.setLE16(3 << 8 | version, at: position + 4) // made on Unix
                directory.setLE16(flags | utf8Flag, at: position + 8)
                if attributes >> 16 == 0 {
                    let mode: UInt32 = name.last == UInt8(ascii: "/") ? 0o040755 : 0o100755
                    directory.setLE32(mode << 16 | attributes & 0xFFFF, at: position + 38)
                }
                locals.append(UInt64(localOffset))
            }
            position = next
        }
        guard !locals.isEmpty else { return 0 }

        for offset in locals {
            try handle.seek(toOffset: offset)
            let header = try handle.read(upToCount: 8) ?? Data()
            guard header.count == 8, header.le32(0) == 0x0403_4B50 else {
                throw RunnerError.job(code: "REPACK_FAILED", message: "Signed archive has a broken local header", retryable: false)
            }
            var flags = Data(count: 2)
            flags.setLE16(header.le16(6) | utf8Flag, at: 0)
            try handle.seek(toOffset: offset + 6)
            try handle.write(contentsOf: flags)
        }
        try handle.seek(toOffset: UInt64(directoryOffset))
        try handle.write(contentsOf: directory)

        return locals.count
    }

    private static func lastSignature(_ signature: UInt32, in data: Data) -> Int? {
        guard data.count >= 22 else { return nil }
        for index in stride(from: data.count - 22, through: 0, by: -1) where data.le32(index) == signature {
            return index
        }
        return nil
    }
}

extension Data {
    fileprivate func le16(_ offset: Int) -> UInt16 {
        let base = startIndex + offset
        return UInt16(self[base]) | UInt16(self[base + 1]) << 8
    }

    fileprivate func le32(_ offset: Int) -> UInt32 {
        let base = startIndex + offset
        return UInt32(self[base]) | UInt32(self[base + 1]) << 8 | UInt32(self[base + 2]) << 16 | UInt32(self[base + 3]) << 24
    }

    fileprivate mutating func setLE32(_ value: UInt32, at offset: Int) {
        setLE16(UInt16(value & 0xFFFF), at: offset)
        setLE16(UInt16(value >> 16), at: offset + 2)
    }

    fileprivate mutating func setLE16(_ value: UInt16, at offset: Int) {
        let base = startIndex + offset
        self[base] = UInt8(value & 0xFF)
        self[base + 1] = UInt8(value >> 8)
    }
}
