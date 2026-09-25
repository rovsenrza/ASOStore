import Foundation

/// A failed API call. Branch on `code`; `message` is only the server's Russian fallback.
nonisolated struct APIError: Error, Equatable, Sendable {
    let code: ErrorCode
    let message: String
    let status: Int
    let requestID: String?
    let fields: [String: [String]]

    init(code: ErrorCode, message: String = "", status: Int = 0, requestID: String? = nil, fields: [String: [String]] = [:]) {
        self.code = code
        self.message = message
        self.status = status
        self.requestID = requestID
        self.fields = fields
    }
}
