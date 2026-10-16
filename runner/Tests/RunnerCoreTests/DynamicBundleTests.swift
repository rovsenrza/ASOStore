import Foundation
import Testing
@testable import RunnerCore

@Suite struct DynamicBundleTests {
    // Header from VK Music's DynamicBundleRuntime data archive, not a Mach-O or appex.
    static let header = Data([0x44, 0x42, 0x52, 0x31, 0x5e, 0x38, 0x8e, 0x03,
                              0x44, 0x42, 0x48, 0x31, 0x14, 0, 0, 0])

    private func withPlugins(_ body: (URL, URL) throws -> Void) throws {
        let app = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        let plugins = app.appendingPathComponent("PlugIns")
        try FileManager.default.createDirectory(at: plugins, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: app) }
        try body(app, plugins)
    }

    @Test func allowsDynamicBundleDataWithoutAnExtensionProfile() throws {
        try withPlugins { app, plugins in
            let archive = plugins.appendingPathComponent("DynamicBundle.dat")
            try Self.header.write(to: archive)
            try FileManager.default.setAttributes([.posixPermissions: 0o644], ofItemAtPath: archive.path)
            try Signer.checkNested(app: app, leased: [])
            #expect(try Data(contentsOf: archive) == Self.header)
        }
    }

    @Test(arguments: ["mach-o", "executable", "symlink", "directory", "extension", "unknown"])
    func rejectsCodeAndUnrecognizedPluginEntries(kind: String) throws {
        try withPlugins { app, plugins in
            var archive = plugins.appendingPathComponent("DynamicBundle.dat")
            switch kind {
            case "directory":
                try FileManager.default.createDirectory(at: archive, withIntermediateDirectories: true)
            case "extension":
                archive = plugins.appendingPathComponent("Tunnel.appex")
                try FileManager.default.createDirectory(at: archive, withIntermediateDirectories: true)
            case "symlink":
                let target = app.appendingPathComponent("data")
                try Self.header.write(to: target)
                try FileManager.default.createSymbolicLink(at: archive, withDestinationURL: target)
            default:
                if kind == "unknown" { archive = plugins.appendingPathComponent("Other.dat") }
                try (kind == "mach-o" ? Data([0xcf, 0xfa, 0xed, 0xfe]) : Self.header).write(to: archive)
                try FileManager.default.setAttributes([.posixPermissions: kind == "executable" ? 0o755 : 0o644], ofItemAtPath: archive.path)
            }
            #expect {
                try Signer.checkNested(app: app, leased: [])
            } throws: { error in
                guard case let RunnerError.job(code, message, _) = error else { return false }
                return code == "NESTED_PROFILE_REQUIRED" && message.contains("PlugIns/" + archive.lastPathComponent)
            }
        }
    }
}

extension SigningTests {
    @Test(.enabled(if: Tools.available("zip")))
    func signsAndSealsDynamicBundleDataWithoutChangingIt() throws {
        let setup = try SigningSetup()
        defer { setup.remove() }
        let unpacked = setup.folder.root.appendingPathComponent("dynamic-data")
        let app = try Signer.unpack(setup.source, into: unpacked, code: "TEST_SETUP")
        let plugins = app.appendingPathComponent("PlugIns")
        try FileManager.default.createDirectory(at: plugins, withIntermediateDirectories: true)
        let resource = plugins.appendingPathComponent("DynamicBundle.dat")
        try DynamicBundleTests.header.write(to: resource)
        try FileManager.default.setAttributes([.posixPermissions: 0o644], ofItemAtPath: resource.path)
        let source = setup.folder.root.appendingPathComponent("dynamic-data.ipa")
        try Shell.require("TEST_SETUP", "zip", ["-qry", source.path, "Payload"], in: unpacked)
        var job = setup.job
        job.source.sha256 = try RequestSigner.sha256(fileAt: source)
        let output = try Signer().sign(job: job, identity: setup.identity, source: source, workDirectory: setup.work)
        let checked = try Signer.unpack(output.ipa, into: setup.work.appendingPathComponent("checked"), code: "TEST_SETUP")
        let signedResource = checked.appendingPathComponent("PlugIns/DynamicBundle.dat")
        #expect(try Data(contentsOf: signedResource) == DynamicBundleTests.header)
        try Data("modified resource".utf8).write(to: signedResource)
        #expect {
            try Signer.verify(app: checked, job: job, profile: setup.profile, workDirectory: setup.work)
        } throws: { error in
            guard case let RunnerError.job(code, message, _) = error else { return false }
            return code == "CODESIGN_VERIFY_FAILED" && message.contains("DynamicBundle.dat")
        }
    }
}
