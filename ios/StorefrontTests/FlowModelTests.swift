import Foundation
import Testing
import UIKit
@testable import Storefront

@Suite("Installation status")
struct InstallationStatusTests {
    @Test(arguments: [
        ("PREPARING", InstallationStatus.preparing),
        ("READY_TO_INSTALL", .readyToInstall),
        ("AUTHORIZED", .authorized),
        ("MANIFEST_FETCHED", .manifestFetched),
        ("DELIVERED", .delivered),
        ("FAILED", .failed),
        ("EXPIRED", .expired),
    ])
    func decodesServerStatuses(raw: String, status: InstallationStatus) throws {
        let decoded = try JSONDecoder().decode([InstallationStatus].self, from: Data("[\"\(raw)\"]".utf8))
        #expect(decoded == [status])
        #expect(status.rawValue == raw)
    }

    @Test func aStatusNewerThanThisBuildStillDecodes() throws {
        let body = String(decoding: try Fixtures.data("installation-ready"), as: UTF8.self)
            .replacingOccurrences(of: "READY_TO_INSTALL", with: "ARCHIVED_BY_ROBOTS")
        let installation = try JSONDecoder.api.decode(APIEnvelope<InstallationDTO>.self, from: Data(body.utf8)).data

        #expect(installation.status == .unknown("ARCHIVED_BY_ROBOTS"))
        #expect(installation.status.rawValue == "ARCHIVED_BY_ROBOTS")
        // Never polled, never offered as an install.
        #expect(!installation.isActive)
        #expect(!installation.status.isPolled)
        #expect(installation.installState == .unavailable)
    }

    @Test func onlyStatusesTheServerMovesOnArePolled() {
        #expect(InstallationStatus.preparing.isPolled)
        #expect(InstallationStatus.authorized.isPolled)
        #expect(InstallationStatus.manifestFetched.isPolled)
        #expect(!InstallationStatus.readyToInstall.isPolled)
        #expect(InstallationStatus.readyToInstall.isActive)
        #expect(!InstallationStatus.delivered.isActive)
    }
}

@Suite("Eligibility reason → CTA")
struct EligibilityPromptTests {
    @Test(arguments: [ErrorCode.unauthenticated, .sessionExpired])
    func signedOutOffersSignIn(reason: ErrorCode) {
        let prompt = EligibilityPrompt(reason: reason)
        #expect(prompt == .signIn)
        #expect(prompt.title == "Войти")
        #expect(!prompt.explainsInAlert)
    }

    @Test(arguments: [ErrorCode.subscriptionRequired, .subscriptionExpired])
    func aMissingSubscriptionOffersRenewal(reason: ErrorCode) {
        let prompt = EligibilityPrompt(reason: reason)
        #expect(prompt == .renew)
        #expect(prompt.title == "Продлить")
        #expect(!prompt.explainsInAlert)
    }

    @Test func aDevicePendingAtAppleWaitsAndExplains() {
        let prompt = EligibilityPrompt(reason: .devicePendingApple)
        #expect(prompt == .waiting(.devicePendingApple))
        #expect(prompt.title == "Ожидание")
        #expect(prompt.explainsInAlert)
        #expect(prompt.explanation == InstallationCoordinator.message(for: APIError(code: .devicePendingApple)))
    }

    @Test(arguments: [ErrorCode.deviceNotEligible, .quotaExhausted, .noEligibleTeam])
    func aBlockedDeviceExplainsWhy(reason: ErrorCode) {
        let prompt = EligibilityPrompt(reason: reason)
        #expect(prompt == .blocked(reason))
        #expect(prompt.title == "Недоступно")
        #expect(prompt.explainsInAlert)
        #expect(prompt.explanation == InstallationCoordinator.message(for: APIError(code: reason)))
    }

    @Test(arguments: [ErrorCode?.none, ErrorCode(rawValue: "REASON_FROM_THE_FUTURE")])
    func unknownReasonsFallBackToTheGenericExplanation(reason: ErrorCode?) {
        let prompt = EligibilityPrompt(reason: reason)
        #expect(prompt == .blocked(reason))
        #expect(prompt.title == "Недоступно")
        #expect(prompt.explainsInAlert)
        #expect(prompt.explanation.contains("пока не может устанавливать"))
        // Never a request id or raw code in front of the customer.
        #expect(!prompt.explanation.contains("REASON"))
    }

    @Test func theRenewalPageIsThePortalsPurchasePage() {
        let environment = APIEnvironment(baseURL: URL(string: "https://ruappstore.com/api/v1")!, mode: .live)
        #expect(environment.renewURL?.absoluteString == "https://ruappstore.com/buy.html")
    }
}

@Suite("Subscription expiry notice")
struct SubscriptionExpiryNoticeTests {
    private let calendar: Calendar = {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Moscow")!
        return calendar
    }()

    /// 12:00 Moscow time.
    private var noon: Date {
        calendar.date(from: DateComponents(year: 2026, month: 11, day: 11, hour: 12))!
    }

    private func subscription(endsIn seconds: TimeInterval?, status: String = "ACTIVE") -> SubscriptionDTO {
        SubscriptionDTO(id: "s", plan: "standard", status: status, startsAt: noon.addingTimeInterval(-86_400 * 30), endsAt: seconds.map { noon.addingTimeInterval($0) })
    }

    private func notice(endsIn seconds: TimeInterval?) -> SubscriptionExpiryNotice? {
        SubscriptionExpiryNotice(subscription: subscription(endsIn: seconds), now: noon, calendar: calendar)
    }

    @Test func nothingToSayWithoutASubscriptionOrAnEndDate() {
        #expect(SubscriptionExpiryNotice(subscription: nil, now: noon, calendar: calendar) == nil)
        #expect(notice(endsIn: nil) == nil)
    }

    @Test func staysQuietUntilAWeekBeforeTheEnd() {
        #expect(notice(endsIn: 86_400 * 7 + 60) == nil)
        #expect(notice(endsIn: 86_400 * 30) == nil)
        #expect(notice(endsIn: 86_400 * 7)?.daysLeft == 7)
    }

    @Test func countsCalendarDays() {
        #expect(notice(endsIn: 86_400 * 3)?.title == "Подписка заканчивается через 3 дня")
        #expect(notice(endsIn: 86_400 * 5)?.title == "Подписка заканчивается через 5 дней")
        #expect(notice(endsIn: 86_400)?.title == "Подписка заканчивается завтра")
        // 13 hours on from noon is already tomorrow; 3 hours on is still today.
        #expect(notice(endsIn: 3_600 * 13)?.title == "Подписка заканчивается завтра")
        #expect(notice(endsIn: 3_600 * 3)?.title == "Подписка заканчивается сегодня")
        #expect(notice(endsIn: 3_600 * 3)?.isExpired == false)
    }

    @Test func saysSoOnceItHasEnded() {
        let ended = notice(endsIn: -60)
        #expect(ended?.isExpired == true)
        #expect(ended?.title == "Подписка закончилась")
        #expect(notice(endsIn: -86_400 * 40)?.isExpired == true)
    }

    @Test func russianPluralsForDays() {
        #expect(SubscriptionExpiryNotice.days(1) == "1 день")
        #expect(SubscriptionExpiryNotice.days(2) == "2 дня")
        #expect(SubscriptionExpiryNotice.days(4) == "4 дня")
        #expect(SubscriptionExpiryNotice.days(5) == "5 дней")
        #expect(SubscriptionExpiryNotice.days(7) == "7 дней")
        #expect(SubscriptionExpiryNotice.days(11) == "11 дней")
        #expect(SubscriptionExpiryNotice.days(21) == "21 день")
    }

    @Test func closingHidesItForTheRestOfThatDayOnly() {
        #expect(!SubscriptionExpiryNotice.isDismissed(at: nil, now: noon, calendar: calendar))
        #expect(SubscriptionExpiryNotice.isDismissed(at: noon.addingTimeInterval(-3_600), now: noon, calendar: calendar))
        #expect(SubscriptionExpiryNotice.isDismissed(at: noon, now: noon.addingTimeInterval(3_600 * 11), calendar: calendar))
        // Next morning it is back.
        #expect(!SubscriptionExpiryNotice.isDismissed(at: noon, now: noon.addingTimeInterval(3_600 * 13), calendar: calendar))
    }

    @Test func membershipStatusFollowsTheBackendStatuses() {
        #expect(MembershipText.status(nil, now: noon) == "Не активирована")
        #expect(MembershipText.status(subscription(endsIn: 86_400), now: noon) == "Активна")
        #expect(MembershipText.status(subscription(endsIn: nil), now: noon) == "Активна")
        #expect(MembershipText.status(subscription(endsIn: -60), now: noon) == "Истекла")
        #expect(MembershipText.status(subscription(endsIn: 86_400, status: "CANCELLED"), now: noon) == "Отменена")
        #expect(MembershipText.status(subscription(endsIn: -60, status: "CANCELLED"), now: noon) == "Отменена")
        // A status newer than this build is not reported as expired while it still runs.
        #expect(MembershipText.status(subscription(endsIn: 86_400, status: "PAUSED"), now: noon) == "Неактивна")
    }
}

@Suite("Dismissed notifications store")
struct DismissedNotificationsStoreTests {
    private func store(limit: Int = 300) -> DismissedNotificationsStore {
        DismissedNotificationsStore(suiteName: "dismissed-tests-\(UUID().uuidString)", limit: limit)
    }

    @Test func keepsTheOrderIdsWereClearedIn() {
        let store = store()
        let ids = (1...40).map { "installation-\($0)" }.shuffled()
        store.save(ids)
        #expect(store.load() == ids)
    }

    @Test func theCapDropsTheOldestIds() {
        let store = store(limit: 3)
        store.save(["a", "b", "c", "d", "e"])
        #expect(store.load() == ["c", "d", "e"])
    }

    @MainActor
    @Test func theCoordinatorClearsInOrderAndForgetsTheOldestFirst() {
        let store = store(limit: 3)
        func coordinator() -> InstallationCoordinator {
            InstallationCoordinator(
                repository: PreparationRepository(api: .stubbed(StubTransport(body: Data()))),
                store: ActiveInstallationStore(url: FileManager.default.temporaryDirectory.appending(path: "active-\(UUID().uuidString).json")),
                dismissedStore: store,
                openURL: { _ in true }
            )
        }

        let first = coordinator()
        for id in ["z", "y", "x", "w"] {
            first.dismissNotification(id)
        }
        first.dismissNotification("y")
        #expect(store.load() == ["y", "x", "w"])

        // After a relaunch the newest are still cleared; only the oldest came back.
        let relaunched = coordinator()
        #expect(relaunched.isNotificationDismissed("w"))
        #expect(relaunched.isNotificationDismissed("y"))
        #expect(!relaunched.isNotificationDismissed("z"))
        relaunched.clearNotifications(["v"])
        #expect(store.load() == ["x", "w", "v"])
    }
}

@MainActor
@Suite("Paged search")
struct PagedSearchTests {
    /// The apps-list fixture, reported as page `page` of two.
    nonisolated private static func page(_ page: Int) throws -> Data {
        var envelope = try #require(try JSONSerialization.jsonObject(with: Fixtures.data("apps-list")) as? [String: Any])
        var meta = try #require(envelope["meta"] as? [String: Any])
        meta["pagination"] = ["page": page, "per_page": 30, "total": 60, "last_page": 2]
        envelope["meta"] = meta
        return try JSONSerialization.data(withJSONObject: envelope)
    }

    private func query(_ request: URLRequest) -> [String: String] {
        let items = request.url.flatMap { URLComponents(url: $0, resolvingAgainstBaseURL: false) }?.queryItems ?? []
        return Dictionary(items.map { ($0.name, $0.value ?? "") }, uniquingKeysWith: { first, _ in first })
    }

    @Test func searchResultsLoadMoreAPageAtATime() async throws {
        let transport = ScriptedTransport { request in
            let page = URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?.queryItems?.first { $0.name == "page" }?.value
            return (200, try Self.page(Int(page ?? "1") ?? 1))
        }
        let catalog = CatalogRepository(api: .stubbed(transport), cache: ResponseCache(directory: FileManager.default.temporaryDirectory.appending(path: "search-tests-\(UUID().uuidString)")))
        let model = CatalogListModel()
        model.filter.search = "почта"

        await model.reload(catalog, fresh: true)
        #expect(model.total == 60)
        #expect(model.more == .idle)
        #expect(!model.apps.isEmpty)

        await model.loadNextPage(catalog)
        #expect(model.more == .exhausted)

        let queries = transport.requests.map(query)
        #expect(queries.map { $0["q"] } == ["почта", "почта"])
        #expect(queries.map { $0["page"] } == ["1", "2"])
        // A search keeps the server's own order, as it did before paging.
        #expect(queries.allSatisfy { $0["sort"] == nil })
    }

    @Test func browsingStillSendsTheSortAndNoSearchTerm() async throws {
        let transport = ScriptedTransport { _ in (200, try Self.page(2)) }
        let catalog = CatalogRepository(api: .stubbed(transport), cache: ResponseCache(directory: FileManager.default.temporaryDirectory.appending(path: "search-tests-\(UUID().uuidString)")))
        let model = CatalogListModel()

        await model.reload(catalog)

        let sent = query(try #require(transport.requests.first))
        #expect(sent["sort"] == "featured")
        #expect(sent["q"] == nil)
    }
}

@MainActor
@Suite("Swipe back")
struct SwipeBackTests {
    @Test func thePopGestureOnlyBeginsWhenThereIsAScreenToGoBackTo() {
        let navigation = UINavigationController(rootViewController: UIViewController())
        SwipeBackDelegate.install(on: navigation)
        let gesture = navigation.interactivePopGestureRecognizer!
        let delegate = gesture.delegate
        #expect(delegate is SwipeBackDelegate)
        #expect(delegate?.gestureRecognizerShouldBegin?(gesture) == false)

        navigation.pushViewController(UIViewController(), animated: false)
        #expect(delegate?.gestureRecognizerShouldBegin?(gesture) == true)

        // Installing again (every screen of the stack does) keeps the one delegate.
        SwipeBackDelegate.install(on: navigation)
        #expect(gesture.delegate === delegate)

        navigation.popViewController(animated: false)
        #expect(delegate?.gestureRecognizerShouldBegin?(gesture) == false)
    }
}
