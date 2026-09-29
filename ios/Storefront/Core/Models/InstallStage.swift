import Foundation

/// What preparation is doing right now, read from the fraction the server reports for each
/// step (SignedBuild::progress in the backend). Drives the progress bar's label and how far
/// the bar may drift on its own before the next report arrives.
nonisolated enum InstallStage: Int, Comparable, Sendable {
    case queued
    case profile
    case waitingForSigner
    case signing
    case verifying
    case finishing
    case ready

    init(progress: Double?) {
        switch progress ?? 0 {
        case ..<0.1: self = .queued
        case ..<0.3: self = .profile
        case ..<0.45: self = .waitingForSigner
        case ..<0.7: self = .signing
        case ..<0.85: self = .verifying
        case ..<1: self = .finishing
        default: self = .ready
        }
    }

    var title: String {
        switch self {
        case .queued: "В очереди"
        case .profile: "Профиль Apple"
        case .waitingForSigner: "Ждём подпись"
        case .signing: "Подписываем"
        case .verifying: "Проверяем"
        case .finishing: "Почти готово"
        case .ready: "Готово"
        }
    }

    /// Where the next report will land: the bar creeps towards it, never past it.
    var ceiling: Double {
        switch self {
        case .queued: 0.15
        case .profile: 0.35
        case .waitingForSigner: 0.5
        case .signing: 0.8
        case .verifying: 0.9
        case .finishing, .ready: 1
        }
    }

    static func < (lhs: Self, rhs: Self) -> Bool {
        lhs.rawValue < rhs.rawValue
    }
}
