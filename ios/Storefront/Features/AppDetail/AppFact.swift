import Foundation

/// One cell of the facts strip under the app page's header: a small label, then a large value
/// (or a symbol) and an optional line under it.
struct AppFact: Identifiable, Equatable {
    enum Kind: String {
        case downloads, age, category, size, version, compatibility, updated
    }

    let kind: Kind
    let label: String
    var value: String?
    var symbol: String?
    var caption: String?
    /// What VoiceOver reads after the label: the fact in full words.
    let spoken: String

    var id: Kind { kind }
}

extension AppFact {
    /// The facts known about an app, in display order. A fact the catalog has no value for is
    /// left out, so the strip never shows a dash.
    static func facts(for app: StoreApp, now: Date = .now) -> [AppFact] {
        var facts: [AppFact] = []

        if let downloads = app.downloads, downloads > 0 {
            let word = AppDetailFormat.installsWord(downloads)
            facts.append(AppFact(
                kind: .downloads,
                label: "Популярность",
                value: AppDetailFormat.compactCount(downloads),
                caption: word,
                spoken: "\(AppDetailFormat.fullCount(downloads)) \(AppDetailFormat.plural(downloads, one: "установка", few: "установки", many: "установок"))"
            ))
        }
        if !app.ageRating.isEmpty {
            facts.append(AppFact(kind: .age, label: "Возраст", value: app.ageRating, caption: "лет", spoken: app.ageRating))
        }
        if !app.category.isEmpty {
            facts.append(AppFact(kind: .category, label: "Категория", symbol: app.systemImage, caption: app.category, spoken: app.category))
        }
        if app.size != "—", !app.size.isEmpty {
            // «88,1 МБ»: the number large, the unit under it.
            let parts = app.size.split(whereSeparator: \.isWhitespace)
            let splits = parts.count == 2
            facts.append(AppFact(
                kind: .size,
                label: "Размер",
                value: splits ? String(parts[0]) : app.size,
                caption: splits ? String(parts[1]) : nil,
                spoken: app.size
            ))
        }
        if !app.version.isEmpty {
            facts.append(AppFact(kind: .version, label: "Версия", value: app.version, spoken: app.version))
        }
        if let minIOS = app.minIOSVersion, !minIOS.isEmpty {
            let version = AppDetailFormat.shortOSVersion(minIOS)
            facts.append(AppFact(kind: .compatibility, label: "Совместимость", value: "iOS \(version)+", spoken: "iOS \(version) и новее"))
        }
        if let updatedAt = app.updatedAt {
            let age = AppDetailFormat.age(of: updatedAt, now: now)
            facts.append(AppFact(kind: .updated, label: "Обновлено", value: age.value, caption: age.caption, spoken: age.text))
        }
        return facts
    }
}
