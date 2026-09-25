/// The screen states every feature must handle (FULL_PLAN §11 "UI states").
/// Retry is offered by the view for every failure case.
nonisolated enum LoadState<Value: Sendable>: Sendable {
    case loading
    case loaded(Value)
    case empty
    case offline
    case unauthorized
    /// Session or activation expired; renewal required.
    case expired
    case failed(APIError)

    init(error: any Error) {
        guard let error = error as? APIError else {
            self = .failed(APIError(code: .internal))
            return
        }

        switch error.code {
        case .offline, .networkError: self = .offline
        case .unauthenticated: self = .unauthorized
        case .sessionExpired: self = .expired
        default: self = .failed(error)
        }
    }

    var value: Value? {
        if case let .loaded(value) = self { value } else { nil }
    }
}
