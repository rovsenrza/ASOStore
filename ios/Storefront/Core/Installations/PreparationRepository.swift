import Foundation

/// Preparation and installation calls for this device (IMPLEMENTATION_PLAN P6-IOS-01).
nonisolated struct PreparationRepository: Sendable {
    let api: APIClient

    func prepare(appID: String) async throws -> InstallationDTO {
        try await api.post("/apps/\(appID)/prepare", as: InstallationDTO.self).data
    }

    func installation(id: String) async throws -> InstallationDTO {
        try await api.get("/installations/\(id)", as: InstallationDTO.self).data
    }

    func authorize(id: String) async throws -> InstallLinkDTO {
        try await api.post("/installations/\(id)/authorize", as: InstallLinkDTO.self).data
    }

    func library() async throws -> [InstallationDTO] {
        try await api.get("/library", as: [InstallationDTO].self).data
    }
}
