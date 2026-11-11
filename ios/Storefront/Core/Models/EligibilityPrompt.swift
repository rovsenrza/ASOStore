/// What the install CTA offers when the server says this user or device cannot install yet
/// (`InstallState.notEligible(reason:)`). The reason is the server's; the app only words it.
nonisolated enum EligibilityPrompt: Hashable, Sendable {
    /// Not signed in, or the session ended: «Войти» opens the account sheet.
    case signIn
    /// No valid subscription: «Продлить» opens the portal's purchase page.
    case renew
    /// The device is still being registered with Apple: tapping explains the wait.
    case waiting(ErrorCode)
    /// The device cannot install for another reason: tapping explains it.
    case blocked(ErrorCode?)

    init(reason: ErrorCode?) {
        switch reason {
        case .unauthenticated?, .sessionExpired?:
            self = .signIn
        case .subscriptionRequired?, .subscriptionExpired?:
            self = .renew
        case .devicePendingApple?:
            self = .waiting(.devicePendingApple)
        default:
            // Quota, no team, device not registered, and reasons newer than this build.
            self = .blocked(reason)
        }
    }

    /// One short word for the capsule.
    var title: String {
        switch self {
        case .signIn: "Войти"
        case .renew: "Продлить"
        case .waiting: "Ожидание"
        case .blocked: "Недоступно"
        }
    }

    /// Title of the explanation alert (`waiting` and `blocked`).
    var alertTitle: String {
        switch self {
        case .waiting: "Устройство регистрируется"
        default: "Установка пока недоступна"
        }
    }

    /// The reason in plain Russian: under the full-width button, in the alert, and for VoiceOver.
    var explanation: String {
        switch self {
        case .signIn:
            "Войдите в аккаунт, чтобы установить приложение."
        case .renew:
            "Подписка не активна. Продлите её, чтобы устанавливать приложения."
        case .waiting(let code), .blocked(let code?):
            switch code {
            case .devicePendingApple, .deviceNotEligible, .quotaExhausted, .noEligibleTeam:
                InstallationCoordinator.message(for: APIError(code: code))
            default:
                Self.generic
            }
        case .blocked(nil):
            Self.generic
        }
    }

    /// Whether tapping shows the explanation alert rather than going somewhere at once.
    var explainsInAlert: Bool {
        switch self {
        case .waiting, .blocked: true
        case .signIn, .renew: false
        }
    }

    private static let generic = "Это устройство пока не может устанавливать приложения. Подробности — в аккаунте."
}
