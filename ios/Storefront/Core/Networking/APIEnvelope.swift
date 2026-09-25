import Foundation

/// Successful `{data, meta, error: null}` envelope (FULL_PLAN §9).
nonisolated struct APIEnvelope<Payload: Decodable & Sendable>: Decodable, Sendable {
    let data: Payload
    let meta: APIMeta
}

nonisolated struct APIMeta: Decodable, Equatable, Sendable {
    let requestId: String?
    let pagination: Pagination?
}

nonisolated struct Pagination: Decodable, Equatable, Sendable {
    let page: Int
    let perPage: Int
    let total: Int
    let lastPage: Int
}

/// What a successful call returns to callers.
nonisolated struct APIResponse<Payload: Sendable>: Sendable {
    let data: Payload
    let meta: APIMeta
}

/// Failed `{data: null, meta, error}` envelope.
nonisolated struct APIErrorEnvelope: Decodable, Sendable {
    struct Body: Decodable, Sendable {
        let code: ErrorCode
        let message: String
        let details: Details?
    }

    struct Details: Decodable, Sendable {
        /// Present for VALIDATION_FAILED: field → messages.
        let fields: [String: [String]]?
    }

    let meta: APIMeta?
    let error: Body
}
