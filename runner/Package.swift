// swift-tools-version: 6.0
import PackageDescription

// Signing runner (IMPLEMENTATION_PLAN P6-RUN-01/02). Leases signing jobs from
// the worker API, re-signs IPAs with zsign and the device profile, checks the
// result, and uploads it. Runs on Linux (Docker) in production; builds on
// macOS for development. No third-party dependencies: hashes come from
// CryptoKit on macOS and the system's OpenSSL libcrypto on Linux.
let package = Package(
    name: "StorefrontRunner",
    platforms: [.macOS(.v14)],
    products: [
        .executable(name: "storefront-runner", targets: ["StorefrontRunner"]),
    ],
    targets: [
        .systemLibrary(name: "COpenSSL", path: "Sources/COpenSSL", pkgConfig: "libcrypto", providers: [.apt(["libssl-dev"])]),
        .target(name: "RunnerCore", dependencies: [
            .target(name: "COpenSSL", condition: .when(platforms: [.linux])),
        ]),
        .executableTarget(name: "StorefrontRunner", dependencies: ["RunnerCore"]),
        .testTarget(name: "RunnerCoreTests", dependencies: ["RunnerCore"], resources: [.copy("Fixtures")]),
    ]
)
