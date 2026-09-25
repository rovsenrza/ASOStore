// swift-tools-version: 6.0
import PackageDescription

// macOS signing runner (IMPLEMENTATION_PLAN P6-RUN-01/02). Leases signing jobs
// from the worker API, re-signs IPAs with the device profile, and uploads the
// result. No third-party dependencies.
let package = Package(
    name: "StorefrontRunner",
    platforms: [.macOS(.v14)],
    products: [
        .executable(name: "storefront-runner", targets: ["StorefrontRunner"]),
    ],
    targets: [
        .target(name: "RunnerCore"),
        .executableTarget(name: "StorefrontRunner", dependencies: ["RunnerCore"]),
        .testTarget(name: "RunnerCoreTests", dependencies: ["RunnerCore"]),
    ]
)
