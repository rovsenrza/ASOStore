import XCTest

/// Catalog screens against the bundled API examples (IMPLEMENTATION_PLAN
/// Phase 4 gate): content, navigation, search, and the empty, offline and
/// server-error states.
final class CatalogUITests: XCTestCase {
    override func setUp() {
        continueAfterFailure = false
    }

    private func launch(_ scenario: String? = nil, tab: String? = nil) -> XCUIApplication {
        let app = XCUIApplication()
        app.launchArguments = ["-apiMode", "mock", "-resetCache"]
        if let scenario {
            app.launchArguments += ["-mockScenario", scenario]
        }
        if let tab {
            app.launchArguments += ["-demoTab", tab]
        }
        app.launch()
        return app
    }

    @MainActor
    func testTodayShowsTheFeedAndOpensAnApp() {
        let app = launch()

        XCTAssertTrue(app.staticTexts["Focus Notes"].firstMatch.waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["Недавно обновлённые"].exists)

        app.staticTexts["Focus Notes"].firstMatch.tap()
        XCTAssertTrue(app.staticTexts["Об этом приложении"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Получить Focus Notes"].exists)
    }

    @MainActor
    func testSearchFindsAppsByTitle() {
        let app = launch(tab: "search")

        let field = app.searchFields.firstMatch
        XCTAssertTrue(field.waitForExistence(timeout: 10))
        field.tap()
        field.typeText("почта")

        XCTAssertTrue(app.staticTexts["Orbit Mail"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.staticTexts["Tempo"].exists)
    }

    @MainActor
    func testBrowseOpensACategory() {
        let app = launch(tab: "apps")

        let productivity = app.buttons["category-productivity"]
        XCTAssertTrue(productivity.waitForExistence(timeout: 10))
        productivity.tap()

        XCTAssertTrue(app.navigationBars["Продуктивность"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["Focus Notes"].exists)
        XCTAssertFalse(app.staticTexts["Orbit Mail"].exists)
    }

    @MainActor
    func testOfflineShowsRetry() {
        let app = launch("offline")

        XCTAssertTrue(app.staticTexts["Нет подключения"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Повторить"].exists)
    }

    @MainActor
    func testEmptyCatalog() {
        let app = launch("empty")

        XCTAssertTrue(app.staticTexts["Здесь пока пусто"].waitForExistence(timeout: 10))
    }

    @MainActor
    func testServerErrorShowsRequestId() {
        let app = launch("error")

        XCTAssertTrue(app.staticTexts["Что-то пошло не так"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts.containing(NSPredicate(format: "label CONTAINS %@", "mock-error")).firstMatch.exists)
    }

    @MainActor
    func testLibraryDoesNotInventInstalledApps() {
        let app = launch(tab: "library")

        XCTAssertTrue(app.staticTexts["Здесь появятся ваши приложения"].waitForExistence(timeout: 10))
    }
}
