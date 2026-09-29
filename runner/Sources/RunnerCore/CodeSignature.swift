import Foundation

/// The embedded code signature of one Mach-O slice, and the checks that stand
/// in for `codesign --verify` on Linux (P6-RUN-01): every code page hash, the
/// Info.plist / CodeResources / entitlements hashes, the CMS signature over
/// the CodeDirectory, and the certificate that made it.
public struct CodeSignature: Sendable {
    public var identifier: String
    public var teamIdentifier: String?
    /// The primary CodeDirectory blob; the CMS signature covers exactly these bytes.
    public var codeDirectory: Data
    public var entitlements: Data?
    public var cms: Data?

    var hashType: UInt8
    var hashSize: Int
    var pageSize: Int
    var codeLimit: Int
    var codeHashes: [Data]
    /// Special slot number (1 Info.plist, 2 requirements, 3 CodeResources, 5 entitlements, 7 DER entitlements) → hash.
    var specialHashes: [Int: Data]
    /// Other blobs of the SuperBlob by type, whose hashes sit in the special slots.
    var blobs: [Int: Data]
    /// The slice bytes the code hashes cover.
    var slice: Data
    /// A bare Mach-O's own Info.plist (`__TEXT,__info_plist`): what special slot 1
    /// hashes when there is no bundle, e.g. the Swift runtime dylibs older apps embed.
    var embeddedInfoPlist: Data?

    /// First 20 bytes of the CodeDirectory hash, as codesign prints CDHash.
    public var cdHash: String { Data(Self.digest(codeDirectory, type: hashType).prefix(20)).hex }

    // MARK: Reading

    /// One signature per architecture slice; throws when a slice is unsigned or malformed.
    public static func read(fileAt url: URL) throws -> [CodeSignature] {
        let data = try Data(contentsOf: url, options: .alwaysMapped)
        let bytes = Bytes(data)
        switch bytes.u32(0, big: true) {
        case 0xCAFE_BABE, 0xCAFE_BABF:
            let is64 = bytes.u32(0, big: true) == 0xCAFE_BABF
            let count = Int(bytes.u32(4, big: true))
            guard count > 0, count < 32 else { throw invalid(url, "bad fat header") }
            return try (0..<count).map { index in
                let entry = 8 + index * (is64 ? 32 : 20)
                let offset = is64 ? Int(bytes.u64(entry + 8, big: true)) : Int(bytes.u32(entry + 8, big: true))
                let size = is64 ? Int(bytes.u64(entry + 16, big: true)) : Int(bytes.u32(entry + 12, big: true))
                guard offset >= 0, size > 0, offset + size <= data.count else { throw invalid(url, "fat slice out of range") }
                return try readSlice(data.subdata(in: offset..<offset + size), url: url)
            }
        default:
            return [try readSlice(data, url: url)]
        }
    }

    static func readSlice(_ slice: Data, url: URL) throws -> CodeSignature {
        let bytes = Bytes(slice)
        let headerSize: Int
        switch bytes.u32(0, big: false) {
        case 0xFEED_FACF: headerSize = 32
        case 0xFEED_FACE: headerSize = 28
        default: throw invalid(url, "not a Mach-O file")
        }

        // LC_CODE_SIGNATURE (0x1D): linkedit_data_command { cmd, cmdsize, dataoff, datasize }.
        let commandCount = Int(bytes.u32(16, big: false))
        var offset = headerSize
        var signatureRange: Range<Int>?
        var infoPlist: Data?
        for _ in 0..<commandCount {
            guard offset + 8 <= slice.count else { throw invalid(url, "load commands out of range") }
            let command = bytes.u32(offset, big: false), size = Int(bytes.u32(offset + 4, big: false))
            if command == 0x1D {
                let start = Int(bytes.u32(offset + 8, big: false)), length = Int(bytes.u32(offset + 12, big: false))
                guard start + length <= slice.count else { throw invalid(url, "signature out of range") }
                signatureRange = start..<start + length
            }
            // LC_SEGMENT_64 (0x19) / LC_SEGMENT (0x1) named __TEXT: look for its __info_plist section.
            if (command == 0x19 || command == 0x1), bytes.name(offset + 8) == "__TEXT" {
                infoPlist = infoPlist ?? embeddedInfoPlist(bytes, segment: offset, is64: command == 0x19)
            }
            guard size >= 8 else { throw invalid(url, "bad load command") }
            offset += size
        }
        guard let signatureRange else { throw invalid(url, "not signed") }

        // SuperBlob (big-endian): magic 0xFADE0CC0, length, count, then {type, offset} per blob.
        let superBlob = Bytes(slice.subdata(in: signatureRange))
        guard superBlob.u32(0, big: true) == 0xFADE_0CC0 else { throw invalid(url, "bad signature SuperBlob") }
        var blobs: [Int: Data] = [:]
        for index in 0..<Int(superBlob.u32(8, big: true)) {
            let type = Int(superBlob.u32(12 + index * 8, big: true)), start = Int(superBlob.u32(16 + index * 8, big: true))
            guard start + 8 <= superBlob.count else { throw invalid(url, "blob out of range") }
            let length = Int(superBlob.u32(start + 4, big: true))
            guard length >= 8, start + length <= superBlob.count else { throw invalid(url, "blob out of range") }
            blobs[type] = superBlob.data.subdata(in: start..<start + length)
        }

        guard let directory = blobs[0], Bytes(directory).u32(0, big: true) == 0xFADE_0C02 else { throw invalid(url, "no CodeDirectory") }
        let cd = Bytes(directory)
        let version = cd.u32(8, big: true)
        let hashOffset = Int(cd.u32(16, big: true)), identOffset = Int(cd.u32(20, big: true))
        let specialCount = Int(cd.u32(24, big: true)), codeCount = Int(cd.u32(28, big: true))
        let codeLimit = Int(cd.u32(32, big: true))
        let hashSize = Int(cd.byte(36)), hashType = cd.byte(37), pageShift = Int(cd.byte(39))
        guard hashSize > 0, hashOffset - specialCount * hashSize >= 0, hashOffset + codeCount * hashSize <= directory.count, codeLimit <= slice.count else {
            throw invalid(url, "bad CodeDirectory")
        }

        let hash = { (at: Int) in directory.subdata(in: at..<at + hashSize) }
        var special: [Int: Data] = [:]
        for slot in stride(from: 1, through: specialCount, by: 1) {
            special[slot] = hash(hashOffset - slot * hashSize)
        }
        let team = version >= 0x20200 ? cd.cString(Int(cd.u32(48, big: true))) : nil

        blobs.removeValue(forKey: 0)
        return CodeSignature(
            identifier: cd.cString(identOffset) ?? "",
            teamIdentifier: team.flatMap { $0.isEmpty ? nil : $0 },
            codeDirectory: directory,
            entitlements: blobs[5].map { $0.subdata(in: 8..<$0.count) },
            cms: blobs[0x10000].map { $0.subdata(in: 8..<$0.count) },
            hashType: hashType,
            hashSize: hashSize,
            pageSize: pageShift == 0 ? codeLimit : 1 << pageShift,
            codeLimit: codeLimit,
            codeHashes: (0..<codeCount).map { hash(hashOffset + $0 * hashSize) },
            specialHashes: special,
            blobs: blobs,
            slice: slice,
            embeddedInfoPlist: infoPlist
        )
    }

    /// The bytes of `__TEXT,__info_plist` in the segment command at `segment`, if any.
    private static func embeddedInfoPlist(_ bytes: Bytes, segment: Int, is64: Bool) -> Data? {
        // segment_command(_64): sections follow the 56 (72) byte header; section(_64) is 68 (80) bytes.
        let sectionCount = Int(bytes.u32(segment + (is64 ? 64 : 48), big: false))
        let first = segment + (is64 ? 72 : 56), stride = is64 ? 80 : 68
        for index in 0..<min(sectionCount, 255) {
            let section = first + index * stride
            guard bytes.name(section) == "__info_plist" else { continue }
            let size = is64 ? Int(bytes.u64(section + 40, big: false)) : Int(bytes.u32(section + 36, big: false))
            let start = Int(bytes.u32(section + (is64 ? 48 : 40), big: false))
            guard size > 0, start > 0, start + size <= bytes.count else { return nil }
            return bytes.data.subdata(in: start..<start + size)
        }
        return nil
    }

    // MARK: Checking

    /// Hashes: every code page, and the special slots that are filled in.
    /// `bundle` is the folder holding Info.plist and _CodeSignature (nil for a bare dylib).
    public func verifyHashes(bundle: URL?) throws {
        guard !codeHashes.isEmpty else { throw failure("no code hashes") }
        for (index, expected) in codeHashes.enumerated() {
            let start = index * pageSize
            guard start < codeLimit else { throw failure("more code hashes than pages") }
            let page = slice.subdata(in: start..<min(start + pageSize, codeLimit))
            guard hashMatches(page, expected) else { throw failure("code page \(index) was modified") }
        }

        for (slot, expected) in specialHashes where expected.contains(where: { $0 != 0 }) {
            let content: Data?
            switch slot {
            case 1: content = bundle.flatMap { try? Data(contentsOf: $0.appendingPathComponent("Info.plist")) } ?? embeddedInfoPlist
            case 3: content = bundle.flatMap { try? Data(contentsOf: $0.appendingPathComponent("_CodeSignature/CodeResources")) }
            default: content = blobs[slot]
            }
            guard let content else { throw failure("special slot \(slot) has no content") }
            guard hashMatches(content, expected) else { throw failure("special slot \(slot) does not match") }
        }
    }

    /// Checks the CMS signature over the CodeDirectory with OpenSSL and returns
    /// the SHA-1 of the certificate that made it. Chain trust is Apple's to judge
    /// on the device; the runner only needs to know which certificate signed.
    public func verifyCMS(workDirectory: URL) throws -> String {
        guard let cms, !cms.isEmpty else { throw failure("no CMS signature") }
        let id = UUID().uuidString
        let cmsFile = workDirectory.appendingPathComponent("cms-\(id).der")
        let contentFile = workDirectory.appendingPathComponent("cd-\(id).bin")
        let signerFile = workDirectory.appendingPathComponent("signer-\(id).pem")
        try cms.write(to: cmsFile)
        try codeDirectory.write(to: contentFile)
        defer {
            for file in [cmsFile, contentFile, signerFile] { try? FileManager.default.removeItem(at: file) }
        }

        let verified = try Shell.run("openssl", [
            "cms", "-verify", "-inform", "DER", "-in", cmsFile.path, "-content", contentFile.path,
            "-binary", "-noverify", "-signer", signerFile.path, "-out", "/dev/null",
        ])
        guard verified.status == 0, let pem = try? String(contentsOf: signerFile, encoding: .utf8), let signer = Self.pemBlocks(pem).first else {
            throw failure("CMS signature does not verify: \(verified.error.trimmingCharacters(in: .whitespacesAndNewlines))")
        }
        return Digest.hash(signer, .sha1).hex.uppercased()
    }

    static func pemBlocks(_ text: String) -> [Data] {
        text.components(separatedBy: "-----BEGIN CERTIFICATE-----").dropFirst().compactMap { block in
            guard let end = block.range(of: "-----END CERTIFICATE-----") else { return nil }
            return Data(base64Encoded: String(block[..<end.lowerBound]), options: .ignoreUnknownCharacters)
        }
    }

    private func hashMatches(_ content: Data, _ expected: Data) -> Bool {
        Data(Self.digest(content, type: hashType).prefix(hashSize)) == expected
    }

    /// CS_HASHTYPE_SHA1 = 1, SHA256 = 2, SHA256_TRUNCATED = 3, SHA384 = 4.
    static func digest(_ data: Data, type: UInt8) -> Data {
        switch type {
        case 1: Digest.hash(data, .sha1)
        case 4: Digest.hash(data, .sha384)
        default: Digest.hash(data, .sha256)
        }
    }

    private func failure(_ message: String) -> RunnerError {
        .job(code: "CODESIGN_VERIFY_FAILED", message: "\(identifier): \(message)", retryable: false)
    }

    private static func invalid(_ url: URL, _ message: String) -> RunnerError {
        .job(code: "CODESIGN_VERIFY_FAILED", message: "\(url.lastPathComponent): \(message)", retryable: false)
    }
}

/// Bounds-checked integer reads over a Data (out of range reads return 0).
private struct Bytes {
    let data: Data
    init(_ data: Data) { self.data = data }
    var count: Int { data.count }

    func byte(_ offset: Int) -> UInt8 {
        offset >= 0 && offset < data.count ? data[data.startIndex + offset] : 0
    }

    func u32(_ offset: Int, big: Bool) -> UInt32 {
        (0..<4).reduce(UInt32(0)) { $0 << 8 | UInt32(byte(offset + (big ? $1 : 3 - $1))) }
    }

    func u64(_ offset: Int, big: Bool) -> UInt64 {
        (0..<8).reduce(UInt64(0)) { $0 << 8 | UInt64(byte(offset + (big ? $1 : 7 - $1))) }
    }

    /// A fixed 16-byte Mach-O name (segment or section), NUL-padded.
    func name(_ offset: Int) -> String {
        let bytes = (0..<16).map { byte(offset + $0) }.prefix { $0 != 0 }
        return String(decoding: bytes, as: UTF8.self)
    }

    func cString(_ offset: Int) -> String? {
        guard offset > 0, offset < data.count else { return nil }
        let start = data.startIndex + offset
        let end = data[start...].firstIndex(of: 0) ?? data.endIndex
        return String(decoding: data[start..<end], as: UTF8.self)
    }
}
