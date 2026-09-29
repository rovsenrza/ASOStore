import Foundation
import Testing
@testable import Storefront

@MainActor
@Suite("Installation coordinator")
struct InstallationCoordinatorTests {
    private final class Opened: @unchecked Sendable {
        var urls: [URL] = []
    }

    private let appID = "01m3by6km065c4v6e72eecghqw"

    private func store() -> ActiveInstallationStore {
        ActiveInstallationStore(url: FileManager.default.temporaryDirectory.appending(path: "active-\(UUID().uuidString).json"))
    }

    private func app(_ state: InstallState = .get) -> StoreApp {
        StoreApp(id: appID, name: "Focus Notes", subtitle: "", category: "", developer: "", systemImage: "app", artwork: .blue, installState: state)
    }

    /// Prepare answers PREPARING; the first poll answers READY_TO_INSTALL.
    private func transport(polls: Int = 1) -> ScriptedTransport {
        let counter = Counter()
        return ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            if path.hasSuffix("/prepare") { return (202, try Fixtures.data("installation-preparing")) }
            if path.hasSuffix("/authorize") { return (200, try Fixtures.data("install-link")) }
            return (200, try Fixtures.data(counter.next() < polls ? "installation-preparing" : "installation-ready"))
        }
    }

    @Test func preparesPollsAndOpensTheInstallLinkWhenReady() async throws {
        let opened = Opened()
        let transport = transport()
        let persisted = store()
        let coordinator = InstallationCoordinator(
            repository: PreparationRepository(api: .stubbed(transport)),
            store: persisted,
            openURL: { url in opened.urls.append(url); return true },
            pollDelay: { _ in .milliseconds(10) }
        )

        await coordinator.act(on: app())
        #expect(coordinator.state(for: app()) == .preparing(progress: 0.5))
        #expect(persisted.load()[appID] != nil)

        try await waitUntil { !opened.urls.isEmpty }
        #expect(opened.urls.first?.scheme == "itms-services")
        #expect(transport.requests.contains { $0.url?.path().hasSuffix("/authorize") == true })
    }

    /// iOS does not say whether a delivered app is still on the phone: the customer may have deleted it.
    @Test func installsADeliveredAppAgain() async throws {
        let opened = Opened()
        let coordinator = InstallationCoordinator(
            repository: PreparationRepository(api: .stubbed(transport())),
            store: store(),
            openURL: { url in opened.urls.append(url); return true },
            pollDelay: { _ in .milliseconds(10) }
        )

        await coordinator.act(on: app(.delivered))
        try await waitUntil { !opened.urls.isEmpty }
        #expect(opened.urls.first?.scheme == "itms-services")
    }

    @Test func resumesAnInstallationAfterRelaunch() async throws {
        let persisted = store()
        persisted.save(appID: appID, installationID: "01j8zq4m6r2x9d3k5v7w1y0b2c")

        let opened = Opened()
        let coordinator = InstallationCoordinator(
            repository: PreparationRepository(api: .stubbed(transport(polls: 0))),
            store: persisted,
            openURL: { url in opened.urls.append(url); return true },
            pollDelay: { _ in .milliseconds(10) }
        )
        await coordinator.resume()

        // The server says it is ready; the user was not in the app, so nothing opens by itself.
        #expect(coordinator.state(for: app()) == .readyToInstall)
        #expect(opened.urls.isEmpty)
    }

    @Test func surfacesServerRefusalsWithoutChangingTheCatalogState() async throws {
        let coordinator = InstallationCoordinator(
            repository: PreparationRepository(api: .stubbed(StubTransport(status: 409, body: Envelopes.error("DEVICE_PENDING_APPLE")))),
            store: store(),
            openURL: { _ in true }
        )

        await coordinator.act(on: app())
        #expect(coordinator.lastError?.code == .devicePendingApple)
        #expect(coordinator.state(for: app()) == .get)
        #expect(InstallationCoordinator.message(for: coordinator.lastError!).contains("Apple"))
    }

    @Test func mapsServerInstallationStatesToTheCTA() throws {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        let library = try decoder.decode(APIEnvelope<[InstallationDTO]>.self, from: Fixtures.data("library")).data
        #expect(library.map(\.installState) == [.delivered, .preparing(progress: 0.2)])
        #expect(library.map(\.isActive) == [false, true])
        #expect(library.first?.deliveredAt == "2026-09-30T10:05:02Z")
        #expect(library.last?.buildNumber == "118")
    }

    private func waitUntil(_ condition: @MainActor () -> Bool) async throws {
        for _ in 0..<200 where !condition() {
            try await Task.sleep(for: .milliseconds(10))
        }
        #expect(condition())
    }
}

nonisolated private final class Counter: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0

    func next() -> Int {
        lock.withLock {
            defer { value += 1 }
            return value
        }
    }
}
