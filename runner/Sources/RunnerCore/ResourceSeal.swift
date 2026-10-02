import Foundation

/// Validates the resources whose manifest is authenticated by CodeDirectory slot 3.
/// Checking the manifest's own hash alone cannot detect stale hashes inside it.
enum ResourceSeal {
    static func verify(bundle: URL) throws {
        func failure(_ message: String) -> RunnerError {
            .job(code: "CODESIGN_VERIFY_FAILED", message: "\(bundle.lastPathComponent): \(message)", retryable: false)
        }
        guard let info = try PropertyListSerialization.propertyList(from: Data(contentsOf: bundle.appendingPathComponent("Info.plist")), format: nil) as? [String: Any],
              let executable = info["CFBundleExecutable"] as? String,
              let manifest = try PropertyListSerialization.propertyList(from: Data(contentsOf: bundle.appendingPathComponent("_CodeSignature/CodeResources")), format: nil) as? [String: Any],
              let entries = (manifest["files2"] ?? manifest["files"]) as? [String: Any] else {
            throw failure("missing or invalid resource seal")
        }
        for table in ["files", "files2"] {
            if (manifest[table] as? [String: Any])?[executable] != nil {
                throw failure("main executable \(executable) is incorrectly sealed as a resource")
            }
        }
        let root = bundle.resolvingSymlinksInPath().path + "/"
        for (path, value) in entries {
            let parts = path.split(separator: "/", omittingEmptySubsequences: false)
            guard !parts.isEmpty, !parts.contains(where: { $0.isEmpty || $0 == "." || $0 == ".." }), !path.contains("\\"), !path.contains("\0") else {
                throw failure("unsafe sealed resource path")
            }
            let entry: [String: Any]
            if let hash = value as? Data { entry = ["hash": hash] }
            else if let dictionary = value as? [String: Any] { entry = dictionary }
            else { throw failure("invalid resource entry \(path)") }
            let file = bundle.appendingPathComponent(path)
            let attributes: [FileAttributeKey: Any]
            do { attributes = try FileManager.default.attributesOfItem(atPath: file.path) }
            catch {
                if entry["optional"] as? Bool == true,
                   (error as NSError).domain == NSCocoaErrorDomain,
                   [NSFileNoSuchFileError, NSFileReadNoSuchFileError].contains((error as NSError).code) { continue }
                throw failure("sealed resource \(path) is missing or unreadable")
            }
            if let target = entry["symlink"] as? String {
                guard attributes[.type] as? FileAttributeType == .typeSymbolicLink,
                      try FileManager.default.destinationOfSymbolicLink(atPath: file.path) == target else {
                    throw failure("sealed symlink \(path) does not match")
                }
                continue
            }
            guard file.resolvingSymlinksInPath().path.hasPrefix(root) else {
                throw failure("sealed resource \(path) escapes the bundle")
            }
            if let expected = entry["cdhash"] as? Data {
                let binary: URL
                if attributes[.type] as? FileAttributeType == .typeDirectory {
                    guard let nested = try PropertyListSerialization.propertyList(from: Data(contentsOf: file.appendingPathComponent("Info.plist")), format: nil) as? [String: Any],
                          let name = nested["CFBundleExecutable"] as? String else {
                        throw failure("nested code \(path) has no executable")
                    }
                    binary = file.appendingPathComponent(name)
                } else { binary = file }
                guard try CodeSignature.read(fileAt: binary).contains(where: { $0.cdHash == expected.hex }) else {
                    throw failure("nested code seal \(path) does not match")
                }
                continue
            }
            guard attributes[.type] as? FileAttributeType == .typeRegular else {
                throw failure("sealed resource \(path) is not a regular file")
            }
            let bytes = try Data(contentsOf: file, options: .alwaysMapped)
            var checked = false
            for (key, algorithm) in [("hash", Digest.Algorithm.sha1), ("hash2", Digest.Algorithm.sha256)] {
                if let expected = entry[key] as? Data {
                    checked = true
                    guard Digest.hash(bytes, algorithm) == expected else { throw failure("sealed resource \(path) hash does not match") }
                }
            }
            guard checked else { throw failure("sealed resource \(path) has no supported hash") }
        }
    }
}
