import Testing
@testable import Storefront

@Suite("Install CTA state")
struct InstallStateTests {
    @Test func unknownServerStatesNeverOfferAnInstall() {
        let state = InstallState(InstallStateDTO(status: "state_from_the_future", reason: nil, progress: nil, installationId: nil))

        #expect(state == .unavailable)
        #expect(!state.isActionable)
    }

    @Test(arguments: [
        (InstallState.get, true),
        (.readyToInstall, true),
        (.updateAvailable, true),
        (.failed(reason: nil), true),
        (.unavailable, false),
        (.notEligible(reason: .deviceNotEligible), false),
        (.preparing(progress: 0.5), false),
        (.delivered, false),
    ])
    func actionability(state: InstallState, actionable: Bool) {
        #expect(state.isActionable == actionable)
    }
}
