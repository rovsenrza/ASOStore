import Foundation
import Dispatch

/// Delta v2 (docs/adr/DELTA_BUILDS.md, "Packs built from the signed tree"): the pack is cut from
/// the signed app folder itself, so no signed IPA is ever zipped, unzipped or hashed. Every regular
/// file under `Payload/` is one entry, written stored (no compressor on the runner) with made-by 0
/// and external attributes 0, the pair zsign's own output always had; the final IPA has no
/// directory entries and lists its entries sorted by the bytes of the name.
extension DeltaPack {
    /// A regular file of the tree.
    struct TreeFile {
        var name: Data
        var path: String
        var size: UInt64
        var crc: UInt32 = 0
    }

    /// Builds the pack for the signed tree (`treeRoot` is the folder that holds `Payload/`) against
    /// the source IPA. Never throws: when delta cannot be used the answer is `.fallback(reason)`
    /// and `output` is removed.
    public static func buildFromTree(source: URL, treeRoot: URL, output: URL, baseSHA256: String) -> Outcome {
        let started = Date()
        do {
            return .pack(try writeFromTree(source: source, treeRoot: treeRoot, output: output, baseSHA256: baseSHA256.lowercased(), started: started))
        } catch let error as Unusable {
            try? FileManager.default.removeItem(at: output)
            return .fallback(error.reason)
        } catch {
            try? FileManager.default.removeItem(at: output)
            return .fallback("I/O error: \(error)")
        }
    }

    /// Regular files under `Payload/`, sorted by name bytes. Symlinks, devices, sockets, names that
    /// are not pure ASCII and too many files make the tree unusable.
    static func walkTree(_ root: URL) throws -> [TreeFile] {
        func type(_ path: String) throws -> (kind: mode_t, size: UInt64) {
            var info = stat()
            guard lstat(path, &info) == 0 else { throw Unusable("cannot stat \(path.split(separator: "/").last ?? "")") }
            return (info.st_mode & mode_t(S_IFMT), UInt64(max(0, info.st_size)))
        }
        let payload = root.appendingPathComponent("Payload").path
        guard try type(payload).kind == mode_t(S_IFDIR) else { throw Unusable("tree has no Payload folder") }

        var files: [TreeFile] = []
        var pending = [(path: payload, relative: "Payload")]
        while let (directory, relative) = pending.popLast() {
            guard let names = try? FileManager.default.contentsOfDirectory(atPath: directory) else { throw Unusable("cannot list \(relative)") }
            for name in names {
                guard !name.utf8.contains(where: { $0 >= 0x80 }) else { throw Unusable("tree has a name that is not ASCII") }
                let path = directory + "/" + name
                let entry = try type(path)
                if entry.kind == mode_t(S_IFDIR) {
                    pending.append((path, relative + "/" + name))
                } else if entry.kind == mode_t(S_IFREG) {
                    guard entry.size < maxArchiveBytes else { throw Unusable("tree has a file of 4 GiB or more") }
                    files.append(TreeFile(name: Data((relative + "/" + name).utf8), path: path, size: entry.size))
                    guard files.count < maxEntries else { throw Unusable("tree has more than 65534 files") }
                } else {
                    throw Unusable("tree has a file that is not a regular file: \(relative)/\(name)")
                }
            }
        }
        guard !files.isEmpty else { throw Unusable("tree has no files") }
        return files.sorted { $0.name.lexicographicallyPrecedes($1.name) }
    }

    /// CRC-32 of the given files, spread over the cores. Memory stays at one 1 MiB buffer per
    /// worker; a file whose size is not what the walk saw (it changed) makes the whole run unusable.
    static func checksums(_ files: [TreeFile], at indexes: [Int]) throws -> [Int: UInt32] {
        final class Results: @unchecked Sendable {
            private let lock = NSLock()
            private var values: [Int: UInt32] = [:]
            private var error: Error?
            var failed: Bool { lock.withLock { error != nil } }
            func set(_ index: Int, _ crc: UInt32) { lock.withLock { values[index] = crc } }
            func fail(_ failure: Error) { lock.withLock { if error == nil { error = failure } } }
            func finish() throws -> [Int: UInt32] { try lock.withLock { if let error { throw error }; return values } }
        }
        let results = Results()
        let cancellation = Cancellation.current
        // Big files first: the last file picked up should not be the longest one.
        let order = indexes.sorted { files[$0].size > files[$1].size }
        DispatchQueue.concurrentPerform(iterations: order.count) { position in
            guard !results.failed else { return }
            let index = order[position]
            do {
                let buffer = UnsafeMutableRawBufferPointer.allocate(byteCount: copyChunk, alignment: 16)
                defer { buffer.deallocate() }
                let reader = try FileReader(URL(fileURLWithPath: files[index].path))
                guard reader.size == files[index].size else { throw Unusable("a file changed while the pack was built") }
                var crc: UInt32 = 0
                var offset: UInt64 = 0
                while offset < reader.size {
                    if cancellation?.isCancelled == true { throw Unusable("cancelled") }
                    let count = Int(min(UInt64(copyChunk), reader.size - offset))
                    try reader.read(at: offset, into: UnsafeMutableRawBufferPointer(rebasing: buffer[0..<count]))
                    crc = DeltaPack.crc32(UnsafeRawBufferPointer(rebasing: buffer[0..<count]), crc: crc)
                    offset += UInt64(count)
                }
                results.set(index, crc)
            } catch {
                results.fail(error)
            }
        }
        return try results.finish()
    }

    /// The tree's files and, for each, whether it comes from the base (kind 0). `known` holds the
    /// files whose `crc` is already filled in. `reserved` are pack-only names no IPA may hold.
    static func classifyTree(source: URL, treeRoot: URL, reserved: [String] = [manifestName]) throws -> (files: [TreeFile], fromBase: [Bool], known: Set<Int>) {
        let base = try Archive.read(source, label: "source")
        var files = try walkTree(treeRoot)

        var baseIndex: [Data: Int] = [:]
        for (index, entry) in base.entries.enumerated() {
            guard baseIndex.updateValue(index, forKey: entry.name) == nil else { throw Unusable("source IPA has duplicate entry names") }
        }
        for name in reserved {
            let entry = Data(name.utf8)
            if baseIndex[entry] != nil || files.contains(where: { $0.name == entry }) {
                throw Unusable("an IPA already has an entry named \(name)")
            }
        }

        // Only a file that the source holds under the same name, in the same size, in a form the
        // base can supply (rules 3 to 5) needs a checksum to decide.
        var candidates: [Int: Archive.Entry] = [:]
        for (index, file) in files.enumerated() {
            guard let original = baseIndex[file.name].map({ base.entries[$0] }), baseUsable(original, name: file.name),
                  UInt64(original.uncompressedSize) == file.size else { continue }
            candidates[index] = original
        }
        var known = Set<Int>()
        for (index, crc) in try checksums(files, at: Array(candidates.keys)) {
            files[index].crc = crc
            known.insert(index)
        }
        let fromBase = files.indices.map { candidates[$0]?.crc == files[$0].crc && known.contains($0) }
        return (files, fromBase, known)
    }

    private static func writeFromTree(source: URL, treeRoot: URL, output: URL, baseSHA256: String, started: Date) throws -> Result {
        guard baseSHA256.count == 64, baseSHA256.allSatisfy({ $0.isHexDigit }) else { throw Unusable("base SHA-256 is not a hex digest") }
        let classified = try classifyTree(source: source, treeRoot: treeRoot)
        var files = classified.files
        let fromBase = classified.fromBase, known = classified.known

        let packIndexes = fromBase.indices.filter { !fromBase[$0] }
        guard packIndexes.count + 1 < maxEntries else { throw Unusable("pack would need more than 65534 entries") }
        let manifest = try manifestJSON(baseSHA256: baseSHA256, pair: RecordPair(madeBy: 0, external: 0), names: files.map(\.name), fromBase: fromBase)

        // Known before a byte is written, and before the pack-only files are read for their checksums.
        let manifestName = Data(Self.manifestName.utf8)
        var expected = UInt64(30 + manifestName.count + manifest.count) + UInt64(46 + manifestName.count) + 22
        for index in packIndexes { expected += UInt64(30 + 46 + 2 * files[index].name.count) + files[index].size }
        let total = files.reduce(UInt64(0)) { $0 + $1.size }
        guard expected < maxArchiveBytes else { throw Unusable("pack would be 4 GiB or more") }
        guard expected * 2 < total else { throw Unusable("pack of \(expected) bytes is not under half of the \(total) byte tree") }

        for (index, crc) in try checksums(files, at: packIndexes.filter { !known.contains($0) }) { files[index].crc = crc }

        let sink = try FileSink(output)
        var directory = Data()
        var written: UInt64 = 0
        do {
            let buffer = UnsafeMutableRawBufferPointer.allocate(byteCount: copyChunk, alignment: 16)
            defer { buffer.deallocate() }
            let cancellation = Cancellation.current
            for index in packIndexes {
                let file = files[index]
                var local = Data()
                local.append32(0x0403_4B50)
                local.append16(20); local.append16(0); local.append16(0)         // version, flags, method (stored)
                local.append16(0); local.append16(dosDate)
                local.append32(file.crc)
                local.append32(UInt32(file.size)); local.append32(UInt32(file.size))
                local.append16(UInt16(file.name.count)); local.append16(0)
                local.append(file.name)
                try sink.write(local)

                var record = Data()
                record.append32(0x0201_4B50)
                record.append16(0); record.append16(20)                         // made by 0, needs 2.0
                record.append16(0); record.append16(0)                          // flags, method
                record.append16(0); record.append16(dosDate)
                record.append32(file.crc)
                record.append32(UInt32(file.size)); record.append32(UInt32(file.size))
                record.append16(UInt16(file.name.count))
                for _ in 0..<4 { record.append16(0) }                           // extra, comment, disk, internal attributes
                record.append32(0)                                              // external attributes
                record.append32(UInt32(written))
                record.append(file.name)
                directory.append(record)
                written += UInt64(local.count)

                let reader = try FileReader(URL(fileURLWithPath: file.path))
                guard reader.size == file.size else { throw Unusable("a file changed while the pack was built") }
                var crc: UInt32 = 0
                var offset: UInt64 = 0
                while offset < file.size {
                    if cancellation?.isCancelled == true { throw Unusable("cancelled") }
                    let count = Int(min(UInt64(copyChunk), file.size - offset))
                    try reader.read(at: offset, into: UnsafeMutableRawBufferPointer(rebasing: buffer[0..<count]))
                    let chunk = UnsafeRawBufferPointer(rebasing: buffer[0..<count])
                    crc = crc32(chunk, crc: crc)
                    try sink.write(chunk)
                    offset += UInt64(count)
                }
                // The header already went out with the checksum read earlier: the bytes must match it.
                guard crc == file.crc else { throw Unusable("a file changed while the pack was built") }
                written += file.size
            }
            try finish(sink, directory: &directory, written: &written, manifest: manifest, packCount: packIndexes.count)
        } catch {
            sink.close()
            throw error
        }
        let sha256 = sink.finish()
        guard written == expected, sink.size == expected else { throw Unusable("pack came out \(sink.size) bytes, expected \(expected)") }

        let check = try Archive.read(output, label: "pack")
        guard check.entries.count == packIndexes.count + 1, check.entries.last?.name == manifestName,
              zip(check.entries, packIndexes).allSatisfy({ $0.name == files[$1].name }) else {
            throw Unusable("pack does not read back as expected")
        }

        return Result(sha256: sha256, baseSHA256: baseSHA256, packBytes: Int64(written), signedBytes: Int64(total),
                      entriesTotal: files.count, entriesPack: packIndexes.count, seconds: Date().timeIntervalSince(started))
    }
}
