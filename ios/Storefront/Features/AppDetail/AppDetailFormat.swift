import Foundation

/// Wording of the numbers and dates on the app page. Pure functions: the interface is Russian
/// whatever the device's language, so everything is formatted for `ru_RU`.
nonisolated enum AppDetailFormat {
    static let locale = Locale(identifier: "ru_RU")

    // MARK: Plurals

    /// The Russian form of a noun for `count`: 1 установка, 2 установки, 5 установок, 11 установок.
    static func plural(_ count: Int, one: String, few: String, many: String) -> String {
        let tens = abs(count) % 100, ones = abs(count) % 10
        if (11...14).contains(tens) { return many }
        if ones == 1 { return one }
        if (2...4).contains(ones) { return few }
        return many
    }

    // MARK: Downloads

    /// A count short enough for the facts strip: 942, «1,2 тыс.», «15 тыс.», «3,4 млн».
    /// Rounded down, so the page never claims more than there is.
    static func compactCount(_ count: Int) -> String {
        let count = max(0, count)
        switch count {
        case ..<1_000: return String(count)
        case ..<1_000_000: return "\(shortened(count, unit: 1_000)) тыс."
        case ..<1_000_000_000: return "\(shortened(count, unit: 1_000_000)) млн"
        default: return "\(shortened(count, unit: 1_000_000_000)) млрд"
        }
    }

    /// One decimal below ten units («1,2»), whole units from there («15»); no «,0».
    private static func shortened(_ count: Int, unit: Int) -> String {
        let whole = count / unit
        let tenth = count % unit * 10 / unit
        return whole < 10 && tenth > 0 ? "\(whole),\(tenth)" : String(whole)
    }

    /// The word under the compact count: it follows the number as read aloud
    /// («1 установка», «3 установки», «1,2 тыс. установок»).
    static func installsWord(_ count: Int) -> String {
        count < 1_000 ? plural(count, one: "установка", few: "установки", many: "установок") : "установок"
    }

    /// The whole number with digit groups, for «Информация»: «12 345».
    static func fullCount(_ count: Int) -> String {
        count.formatted(.number.grouping(.automatic).locale(locale))
    }

    // MARK: Versions

    /// The system version as people say it: «17» for 17.0, «16.4» for 16.4.
    static func shortOSVersion(_ version: String) -> String {
        var parts = version.trimmingCharacters(in: .whitespaces).split(separator: ".").map(String.init)
        while parts.count > 1, parts.last?.allSatisfy({ $0 == "0" }) == true {
            parts.removeLast()
        }
        return parts.joined(separator: ".")
    }

    /// «3.4.0 (340)»; just the version when the build adds nothing.
    static func versionAndBuild(version: String, build: String) -> String {
        if version.isEmpty { return build.isEmpty ? "—" : build }
        return build.isEmpty || build == version ? version : "\(version) (\(build))"
    }

    /// The line under «Что нового»: «Версия 3.4.0 · обновлено 22 сентября 2026 г.», or whichever
    /// half is known; nil when neither is.
    static func versionLine(version: String, releasedAt: Date?, timeZone: TimeZone = .current) -> String? {
        let date = releasedAt.map { fullDate($0, timeZone: timeZone) }
        switch (version.isEmpty, date) {
        case (false, let date?): return "Версия \(version) · обновлено \(date)"
        case (false, nil): return "Версия \(version)"
        case (true, let date?): return "Обновлено \(date)"
        case (true, nil): return nil
        }
    }

    // MARK: Dates

    /// How long ago an update came out, split for the facts strip: a large value and the words under it.
    nonisolated struct Age: Equatable, Sendable {
        let value: String
        let caption: String?

        /// «3 дня назад», «Сегодня».
        var text: String {
            caption.map { "\(value) \($0)" } ?? value
        }
    }

    static func age(of date: Date, now: Date = .now, calendar: Calendar = russianCalendar) -> Age {
        let days = calendar.dateComponents([.day], from: calendar.startOfDay(for: date), to: calendar.startOfDay(for: now)).day ?? 0
        switch days {
        // A date in the future is a clock that is off, not a prediction.
        case ...0: return Age(value: "Сегодня", caption: nil)
        case 1: return Age(value: "Вчера", caption: nil)
        case ..<7: return Age(value: String(days), caption: "\(plural(days, one: "день", few: "дня", many: "дней")) назад")
        case ..<30:
            let weeks = days / 7
            return Age(value: String(weeks), caption: "\(plural(weeks, one: "неделю", few: "недели", many: "недель")) назад")
        case ..<365:
            let months = min(11, days / 30)
            return Age(value: String(months), caption: "\(plural(months, one: "месяц", few: "месяца", many: "месяцев")) назад")
        default:
            let years = days / 365
            return Age(value: String(years), caption: "\(plural(years, one: "год", few: "года", many: "лет")) назад")
        }
    }

    /// «22 сентября 2026 г.»
    static func fullDate(_ date: Date, timeZone: TimeZone = .current) -> String {
        var style = Date.FormatStyle(date: .long, time: .omitted, locale: locale)
        style.timeZone = timeZone
        return date.formatted(style)
    }

    static var russianCalendar: Calendar {
        var calendar = Calendar(identifier: .gregorian)
        calendar.locale = locale
        return calendar
    }

    // MARK: Text

    /// Whether a description is long enough to fold behind «Ещё»: more than four lines' worth.
    static func isLong(_ text: String) -> Bool {
        text.count > 280 || text.split(separator: "\n", omittingEmptySubsequences: false).count > 4
    }
}
