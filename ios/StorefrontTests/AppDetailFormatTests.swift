import Foundation
import Testing
@testable import Storefront

@Suite("App page wording")
struct AppDetailFormatTests {
    @Test(arguments: [
        (0, "установок"), (1, "установка"), (2, "установки"), (4, "установки"), (5, "установок"),
        (11, "установок"), (12, "установок"), (14, "установок"), (21, "установка"), (22, "установки"),
        (25, "установок"), (100, "установок"), (101, "установка"), (111, "установок"), (112, "установок"),
        (122, "установки"), (1_001, "установка"),
    ])
    func russianPlural(count: Int, expected: String) {
        #expect(AppDetailFormat.plural(count, one: "установка", few: "установки", many: "установок") == expected)
    }

    @Test(arguments: [
        (0, "0"), (7, "7"), (999, "999"),
        (1_000, "1 тыс."), (1_234, "1,2 тыс."), (1_999, "1,9 тыс."), (9_950, "9,9 тыс."),
        (10_000, "10 тыс."), (15_700, "15 тыс."), (999_999, "999 тыс."),
        (1_000_000, "1 млн"), (3_450_000, "3,4 млн"), (12_000_000, "12 млн"),
        (2_500_000_000, "2,5 млрд"), (-5, "0"),
    ])
    func compactCount(count: Int, expected: String) {
        #expect(AppDetailFormat.compactCount(count) == expected)
    }

    @Test func installsWordFollowsTheNumberAsRead() {
        #expect(AppDetailFormat.installsWord(1) == "установка")
        #expect(AppDetailFormat.installsWord(3) == "установки")
        #expect(AppDetailFormat.installsWord(942) == "установки")
        #expect(AppDetailFormat.installsWord(1_001) == "установок")
        #expect(AppDetailFormat.installsWord(1_234) == "установок")
    }

    @Test func fullCountGroupsDigits() {
        // The group separator is a no-break space of one kind or another, depending on the system.
        let digits = AppDetailFormat.fullCount(1_234_567).filter(\.isNumber)
        #expect(digits == "1234567")
        #expect(AppDetailFormat.fullCount(1_234_567).count > digits.count)
        #expect(AppDetailFormat.fullCount(42) == "42")
    }

    @Test(arguments: [("17.0", "17"), ("16.4", "16.4"), ("15.0.0", "15"), ("16", "16"), ("17.0.1", "17.0.1"), (" 18.0 ", "18"), ("0", "0")])
    func shortOSVersion(version: String, expected: String) {
        #expect(AppDetailFormat.shortOSVersion(version) == expected)
    }

    @Test func versionAndBuild() {
        #expect(AppDetailFormat.versionAndBuild(version: "3.4.0", build: "340") == "3.4.0 (340)")
        #expect(AppDetailFormat.versionAndBuild(version: "3.4.0", build: "") == "3.4.0")
        #expect(AppDetailFormat.versionAndBuild(version: "7", build: "7") == "7")
        #expect(AppDetailFormat.versionAndBuild(version: "", build: "340") == "340")
        #expect(AppDetailFormat.versionAndBuild(version: "", build: "") == "—")
    }

    private static let utc = TimeZone(identifier: "UTC")!

    private static var calendar: Calendar {
        var calendar = AppDetailFormat.russianCalendar
        calendar.timeZone = utc
        return calendar
    }

    private static func date(_ string: String) -> Date {
        ISO8601DateFormatter().date(from: string)!
    }

    @Test(arguments: [
        ("2026-11-11T08:00:00Z", "Сегодня"),
        ("2026-11-10T23:59:00Z", "Вчера"),
        ("2026-11-09T12:00:00Z", "2 дня назад"),
        ("2026-11-06T12:00:00Z", "5 дней назад"),
        ("2026-11-04T12:00:00Z", "1 неделю назад"),
        ("2026-10-27T12:00:00Z", "2 недели назад"),
        ("2026-10-13T12:00:00Z", "4 недели назад"),
        ("2026-10-11T12:00:00Z", "1 месяц назад"),
        ("2026-09-01T12:00:00Z", "2 месяца назад"),
        ("2026-05-01T12:00:00Z", "6 месяцев назад"),
        ("2025-11-20T12:00:00Z", "11 месяцев назад"),
        ("2025-11-01T12:00:00Z", "1 год назад"),
        ("2024-06-01T12:00:00Z", "2 года назад"),
        ("2020-01-01T12:00:00Z", "6 лет назад"),
        // A release date ahead of the device's clock is not "in N days".
        ("2026-12-01T12:00:00Z", "Сегодня"),
    ])
    func ageOfAnUpdate(released: String, expected: String) {
        let now = Self.date("2026-11-11T12:00:00Z")
        #expect(AppDetailFormat.age(of: Self.date(released), now: now, calendar: Self.calendar).text == expected)
    }

    @Test func ageSplitsIntoValueAndCaptionForTheFactsStrip() {
        let now = Self.date("2026-11-11T12:00:00Z")
        let age = AppDetailFormat.age(of: Self.date("2026-10-27T12:00:00Z"), now: now, calendar: Self.calendar)
        #expect(age == AppDetailFormat.Age(value: "2", caption: "недели назад"))
        #expect(AppDetailFormat.age(of: now, now: now, calendar: Self.calendar).caption == nil)
    }

    @Test func fullDateIsRussianWhateverTheDeviceLanguage() {
        let text = AppDetailFormat.fullDate(Self.date("2026-09-22T21:42:26Z"), timeZone: Self.utc)
        #expect(text.hasPrefix("22 сентября 2026"))
    }

    @Test func versionLine() {
        let date = Self.date("2026-09-22T21:42:26Z")
        let line = AppDetailFormat.versionLine(version: "3.4.0", releasedAt: date, timeZone: Self.utc)
        #expect(line?.hasPrefix("Версия 3.4.0 · обновлено 22 сентября 2026") == true)
        #expect(AppDetailFormat.versionLine(version: "3.4.0", releasedAt: nil) == "Версия 3.4.0")
        #expect(AppDetailFormat.versionLine(version: "", releasedAt: date, timeZone: Self.utc)?.hasPrefix("Обновлено 22 сентября") == true)
        #expect(AppDetailFormat.versionLine(version: "", releasedAt: nil) == nil)
    }

    @Test func longTextIsFolded() {
        #expect(!AppDetailFormat.isLong(""))
        #expect(!AppDetailFormat.isLong(String(repeating: "а", count: 280)))
        #expect(AppDetailFormat.isLong(String(repeating: "а", count: 281)))
        #expect(!AppDetailFormat.isLong("раз\nдва\nтри\nчетыре"))
        #expect(AppDetailFormat.isLong("раз\nдва\nтри\nчетыре\nпять"))
    }
}
