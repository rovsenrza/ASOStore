import Foundation

/// «Подписка заканчивается через 3 дня»: shown when the subscription ends within a week,
/// or already has. Built only from the dates the server sent; nothing is assumed about access.
nonisolated struct SubscriptionExpiryNotice: Equatable, Sendable {
    /// How close to the end the notice starts to show.
    static let leadDays = 7
    /// UserDefaults key: when the banner was last closed (seconds since the reference date).
    static let dismissedAtKey = "subscriptionNoticeDismissedAt"

    /// Whole calendar days until the end: 0 is today, negative once it has passed.
    let daysLeft: Int
    let isExpired: Bool
    let endsAt: Date

    init?(subscription: SubscriptionDTO?, now: Date = .now, calendar: Calendar = .current) {
        // No end date means no limit: nothing to warn about.
        guard let endsAt = subscription?.endsAt else { return nil }
        guard endsAt.timeIntervalSince(now) <= TimeInterval(Self.leadDays * 86_400) else { return nil }
        self.endsAt = endsAt
        isExpired = endsAt <= now
        daysLeft = calendar.dateComponents([.day], from: calendar.startOfDay(for: now), to: calendar.startOfDay(for: endsAt)).day ?? 0
    }

    var title: String {
        if isExpired { return "Подписка закончилась" }
        switch daysLeft {
        case ...0: return "Подписка заканчивается сегодня"
        case 1: return "Подписка заканчивается завтра"
        default: return "Подписка заканчивается через \(Self.days(daysLeft))"
        }
    }

    var detail: String {
        isExpired
            ? "Продлите её, чтобы устанавливать и обновлять приложения."
            : "Продлите заранее, чтобы установка и обновления не прервались."
    }

    /// At most once a day: hidden for the rest of the day it was closed on.
    static func isDismissed(at dismissedAt: Date?, now: Date = .now, calendar: Calendar = .current) -> Bool {
        guard let dismissedAt else { return false }
        return calendar.isDate(dismissedAt, inSameDayAs: now)
    }

    /// «2 дня», «5 дней», «21 день».
    static func days(_ count: Int) -> String {
        let tens = count % 100, ones = count % 10
        let word = if (11...14).contains(tens) {
            "дней"
        } else if ones == 1 {
            "день"
        } else if (2...4).contains(ones) {
            "дня"
        } else {
            "дней"
        }
        return "\(count) \(word)"
    }
}
