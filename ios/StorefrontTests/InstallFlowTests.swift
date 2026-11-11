import Foundation
import Testing
@testable import Storefront

/// Double taps, poll stop conditions and backoff, background behaviour and local notifications.
@MainActor
@Suite("Installation flow")
struct InstallFlowTests {
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

    /// Prepare answers PREPARING; every poll is answered by `poll`.
    private func transport(poll: @escaping @Sendable (Int) throws -> (Int, Data)) -> ScriptedTransport {
        let counter = FlowCounter()
        return ScriptedTransport { request in
            let path = request.url?.path() ?? ""
            if path.hasSuffix("/prepare") { return (202, try Fixtures.data("installation-preparing")) }
            if path.hasSuffix("/authorize") { return (200, try Fixtures.data("install-link")) }
            return try poll(counter.next())
        }
    }

    private func polls(_ transport: ScriptedTransport) -> Int {
        transport.requests.filter { $0.httpMethod == "GET" && $0.url?.path().contains("/installations/") == true }.count
    }

    private func prepares(_ transport: ScriptedTransport) -> Int {
        transport.requests.filter { $0.url?.path().hasSuffix("/prepare") == true }.count
    }

    private func coordinator(
        _ transport: ScriptedTransport,
        store: ActiveInstallationStore? = nil,
        notifier: any InstallNotifying = SilentInstallNotifier(),
        opened: Opened = Opened(),
        pollDelay: @escaping @Sendable (PollAttempt) -> Duration = { _ in .milliseconds(10) },
        stallLimit: TimeInterval = 15 * 60,
        now: @escaping @Sendable () -> Date = { Date() }
    ) -> InstallationCoordinator {
        InstallationCoordinator(
            repository: PreparationRepository(api: .stubbed(transport)),
            store: store ?? self.store(),
            dismissedStore: DismissedNotificationsStore(suiteName: "flow-tests-\(UUID().uuidString)"),
            notifier: notifier,
            openURL: { url in opened.urls.append(url); return true },
            pollDelay: pollDelay,
            stallLimit: stallLimit,
            now: now
        )
    }

    // MARK: Double tap

    @Test func aSecondTapWhileTheRequestIsInFlightDoesNothing() async throws {
        let transport = transport { _ in (200, try Fixtures.data("installation-preparing")) }
        let coordinator = coordinator(transport)
        let app = app()

        async let first: Void = coordinator.act(on: app)
        async let second: Void = coordinator.act(on: app)
        _ = await (first, second)

        #expect(prepares(transport) == 1)
        #expect(coordinator.lastError == nil)
    }

    @Test func theButtonIsBusyWhileTheRequestIsInFlight() async throws {
        let transport = transport { _ in (200, try Fixtures.data("installation-preparing")) }
        let coordinator = coordinator(transport)
        #expect(!coordinator.isBusy(appID))

        let tap = Task { await coordinator.act(on: app()) }
        try await waitUntil { coordinator.isBusy(appID) }
        await tap.value

        #expect(!coordinator.isBusy(appID))
        #expect(coordinator.state(for: app()) == .preparing(progress: 0.5))
    }

    // MARK: Poll stop conditions

    @Test func stopsPollingAndForgetsAnInstallationTheServerNoLongerKnows() async throws {
        let transport = transport { _ in (404, Envelopes.error("NOT_FOUND")) }
        let persisted = store()
        let coordinator = coordinator(transport, store: persisted)

        await coordinator.act(on: app())
        try await waitUntil { coordinator.installations[appID] == nil }

        #expect(persisted.load()[appID] == nil)
        #expect(coordinator.state(for: app()) == .get)
        try await expectNoMorePolls(transport)
    }

    @Test(arguments: ["UNAUTHENTICATED", "SESSION_EXPIRED"])
    func stopsPollingWhenSignedOutButKeepsTheInstallation(code: String) async throws {
        let transport = transport { _ in (401, Envelopes.error(code)) }
        let persisted = store()
        let coordinator = coordinator(transport, store: persisted)

        await coordinator.act(on: app())
        try await waitUntil { polls(transport) == 1 }
        try await expectNoMorePolls(transport)

        // Still there for resume() after the next sign-in.
        #expect(persisted.load()[appID] != nil)
        #expect(coordinator.state(for: app()) == .preparing(progress: 0.5))
    }

    @Test func backsOffWhileRequestsFail() async throws {
        let seen = Recorder<PollAttempt>()
        let transport = transport { _ in (500, Envelopes.error("INTERNAL")) }
        let coordinator = coordinator(transport, pollDelay: { poll in
            seen.append(poll)
            return .milliseconds(5)
        })

        await coordinator.act(on: app())
        try await waitUntil { seen.values.count >= 4 }

        #expect(Array(seen.values.prefix(4)).map(\.failures) == [0, 1, 2, 3])
        // The attempt counter only advances on answers, so a recovery does not start slow.
        #expect(Set(seen.values.prefix(4).map(\.attempt)) == [0])
        coordinator.pausePolling()
    }

    @Test func defaultDelayGrowsToFiveSecondsAndToFifteenAfterFailures() {
        let delay = InstallationCoordinator.defaultPollDelay
        #expect(delay(PollAttempt(attempt: 0)) == .seconds(1))
        #expect(delay(PollAttempt(attempt: 3)) == .seconds(4))
        #expect(delay(PollAttempt(attempt: 40)) == .seconds(5))
        #expect(delay(PollAttempt(attempt: 0, failures: 1)) == .seconds(5))
        #expect(delay(PollAttempt(attempt: 9, failures: 2)) == .seconds(10))
        #expect(delay(PollAttempt(attempt: 0, failures: 3)) == .seconds(15))
        #expect(delay(PollAttempt(attempt: 0, failures: 50)) == .seconds(15))
    }

    @Test func givesUpOnAPreparationThatStopsMovingButKeepsTheRow() async throws {
        let clock = TestClock()
        let transport = transport { _ in (200, try Fixtures.data("installation-preparing")) }
        let coordinator = coordinator(transport, stallLimit: 15 * 60, now: { clock.now })

        await coordinator.act(on: app())
        try await waitUntil { polls(transport) >= 2 }

        // A quarter of an hour without any progress.
        clock.advance(15 * 60 + 1)
        try await expectNoMorePolls(transport)
        #expect(coordinator.state(for: app()) == .preparing(progress: 0.5))

        // Acting on it looks again.
        let before = polls(transport)
        await coordinator.act(on: app())
        try await waitUntil { polls(transport) > before }
        coordinator.pausePolling()
    }

    @Test func keepsPollingWhileTheServerReportsProgress() async throws {
        let clock = TestClock()
        // Each answer reports a little more progress than the last.
        let transport = transport { index in
            let body = String(decoding: try Fixtures.data("installation-preparing"), as: UTF8.self)
                .replacingOccurrences(of: "\"progress\": 0.5", with: "\"progress\": 0.\(50 + index % 40)")
            return (200, Data(body.utf8))
        }
        let coordinator = coordinator(transport, stallLimit: 60, now: { clock.now })

        await coordinator.act(on: app())
        for _ in 0..<5 {
            let before = polls(transport)
            clock.advance(50)
            try await waitUntil { polls(transport) >= before + 2 }
        }
        coordinator.pausePolling()
    }

    // MARK: Background

    @Test func pausesInTheBackgroundAndResumesWhenActive() async throws {
        let transport = transport { _ in (200, try Fixtures.data("installation-preparing")) }
        let coordinator = coordinator(transport)

        await coordinator.act(on: app())
        try await waitUntil { polls(transport) >= 1 }

        // A preparation is in flight: the caller may grant a grace period, then pauses.
        #expect(coordinator.enterBackground())
        coordinator.pausePolling()
        try await expectNoMorePolls(transport)

        let before = polls(transport)
        await coordinator.becomeActive()
        try await waitUntil { polls(transport) > before + 1 }
        coordinator.pausePolling()
    }

    @Test func stopsAtOnceInTheBackgroundWhenNothingIsBeingPrepared() async throws {
        let transport = transport { _ in (200, try Fixtures.data("installation-ready")) }
        let coordinator = coordinator(transport)

        #expect(!coordinator.enterBackground())
        #expect(!coordinator.hasPreparationInFlight)
    }

    @Test func aBuildReadyInTheBackgroundIsAnnouncedAndOpenedOnReturn() async throws {
        let opened = Opened()
        let notifier = FakeNotifier(permission: .allowed)
        let transport = transport { index in (200, try Fixtures.data(index < 1 ? "installation-preparing" : "installation-ready")) }
        let coordinator = coordinator(transport, notifier: notifier, opened: opened, pollDelay: { _ in .milliseconds(40) })

        await coordinator.act(on: app())
        #expect(coordinator.enterBackground())

        try await waitUntil { !notifier.ready.isEmpty }
        #expect(notifier.ready.map(\.app.name) == ["Focus Notes"])
        // An install link cannot be opened from the background.
        #expect(opened.urls.isEmpty)
        #expect(coordinator.lastError == nil)

        await coordinator.becomeActive()
        try await waitUntil { !opened.urls.isEmpty }
        #expect(opened.urls.first?.scheme == "itms-services")
        coordinator.pausePolling()
    }

    @Test func aBuildReadyWhileActiveOpensWithoutANotification() async throws {
        let opened = Opened()
        let notifier = FakeNotifier(permission: .allowed)
        let transport = transport { _ in (200, try Fixtures.data("installation-ready")) }
        let coordinator = coordinator(transport, notifier: notifier, opened: opened)

        await coordinator.act(on: app())
        try await waitUntil { !opened.urls.isEmpty }
        #expect(notifier.ready.isEmpty)
        coordinator.pausePolling()
    }

    // MARK: Notifications

    @Test func asksForNotificationsWhenTheFirstInstallStartsNotBefore() async throws {
        let notifier = FakeNotifier(permission: .notDetermined)
        let transport = transport { _ in (200, try Fixtures.data("installation-preparing")) }
        let coordinator = coordinator(transport, notifier: notifier)

        await coordinator.resume()
        #expect(notifier.permissionRequests == 0)

        await coordinator.act(on: app())
        try await waitUntil { notifier.permissionRequests == 1 }
        coordinator.pausePolling()
    }

    @Test func tappingAReadyNotificationRowInstallsIt() async throws {
        let opened = Opened()
        let transport = transport { _ in (200, try Fixtures.data("installation-ready")) }
        let coordinator = coordinator(transport, opened: opened)
        let ready = try JSONDecoder.api.decode(APIEnvelope<InstallationDTO>.self, from: Fixtures.data("installation-ready")).data

        await coordinator.installNow(ready)

        #expect(opened.urls.first?.scheme == "itms-services")
        #expect(prepares(transport) == 0)
        #expect(coordinator.installations[appID]?.id == ready.id)
        coordinator.pausePolling()
    }

    @Test func clearingANotificationWithdrawsIt() async throws {
        let notifier = FakeNotifier(permission: .allowed)
        let coordinator = coordinator(transport { _ in (200, try Fixtures.data("installation-ready")) }, notifier: notifier)

        coordinator.dismissNotification("a")
        coordinator.dismissNotification("a")
        coordinator.clearNotifications(["b", "a"])

        #expect(notifier.withdrawn == ["a", "b"])
        #expect(coordinator.isNotificationDismissed("b"))
    }

    // MARK: Helpers

    private func waitUntil(_ condition: @MainActor () -> Bool) async throws {
        for _ in 0..<300 where !condition() {
            try await Task.sleep(for: .milliseconds(10))
        }
        #expect(condition())
    }

    /// Lets any poll in flight land, then checks that no new one starts.
    private func expectNoMorePolls(_ transport: ScriptedTransport) async throws {
        try await Task.sleep(for: .milliseconds(150))
        let settled = polls(transport)
        try await Task.sleep(for: .milliseconds(200))
        #expect(polls(transport) == settled)
    }
}

/// Records what the coordinator asks of the notification centre.
@MainActor
final class FakeNotifier: InstallNotifying {
    private var current: NotificationPermission
    private(set) var permissionRequests = 0
    private(set) var ready: [InstallationDTO] = []
    private(set) var withdrawn: [String] = []

    init(permission: NotificationPermission) {
        current = permission
    }

    func permission() async -> NotificationPermission { current }

    func requestPermission() async -> NotificationPermission {
        permissionRequests += 1
        if current == .notDetermined { current = .allowed }
        return current
    }

    func notifyReady(_ installation: InstallationDTO) async {
        ready.append(installation)
    }

    func withdraw(installationID: String) {
        withdrawn.append(installationID)
    }
}

nonisolated final class TestClock: @unchecked Sendable {
    private let lock = NSLock()
    private var date = Date(timeIntervalSince1970: 1_800_000_000)

    var now: Date {
        lock.withLock { date }
    }

    func advance(_ seconds: TimeInterval) {
        lock.withLock { date += seconds }
    }
}

nonisolated final class Recorder<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var _values: [Value] = []

    var values: [Value] {
        lock.withLock { _values }
    }

    func append(_ value: Value) {
        lock.withLock { _values.append(value) }
    }
}

nonisolated private final class FlowCounter: @unchecked Sendable {
    private let lock = NSLock()
    private var value = 0

    func next() -> Int {
        lock.withLock {
            defer { value += 1 }
            return value
        }
    }
}
