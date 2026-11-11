import Foundation

/// One line of the app page's «Информация» card: a fact, or a link that opens outside the app.
struct AppInfoRow: Identifiable, Equatable {
    let title: String
    var value: String = ""
    var url: URL?
    /// Symbol at the end of a link row.
    var symbol = "arrow.up.right"

    var id: String { title }
}

extension AppInfoRow {
    /// Everything known about an app, in display order; rows without a value are left out.
    static func rows(for app: StoreApp) -> [AppInfoRow] {
        var rows: [AppInfoRow] = []
        func add(_ title: String, _ value: String?) {
            guard let value, !value.isEmpty, value != "—" else { return }
            rows.append(AppInfoRow(title: title, value: value))
        }

        add("Продавец", app.developer)
        add("Категория", app.category)
        add("Размер", app.size)
        add("Совместимость", app.minIOSVersion.flatMap { $0.isEmpty ? nil : "iOS \(AppDetailFormat.shortOSVersion($0)) и новее" } ?? "iPhone")
        add("Возраст", app.ageRating)
        add("Обновлено", app.updatedAt.map { AppDetailFormat.fullDate($0) })
        add("Установки", app.downloads.flatMap { $0 > 0 ? AppDetailFormat.fullCount($0) : nil })
        if !app.version.isEmpty {
            add("Версия", AppDetailFormat.versionAndBuild(version: app.version, build: app.buildNumber))
        }

        if let url = app.supportURL {
            rows.append(AppInfoRow(title: "Поддержка", url: url, symbol: url.scheme == "mailto" ? "envelope" : "arrow.up.right"))
        }
        if let url = app.privacyURL {
            rows.append(AppInfoRow(title: "Конфиденциальность", url: url))
        }
        if let url = app.publisherURL {
            rows.append(AppInfoRow(title: "Сайт разработчика", url: url))
        }
        return rows
    }
}
