import SwiftUI

/// Top of every tab: the logo, and a glass capsule with notifications and the account.
struct StoreHeader: View {
    @Environment(AppRouter.self) private var router
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?

    var body: some View {
        HStack {
            BrandMark(size: 42)
            Spacer()
            HStack(spacing: 4) {
                Button {
                    router.sheet = .notifications
                } label: {
                    Image(systemName: "bell.fill")
                        .frame(width: 44, height: 44)
                        .overlay(alignment: .topTrailing) {
                            if pending > 0 {
                                Circle().fill(.red).frame(width: 9, height: 9).offset(x: -8, y: 9)
                            }
                        }
                }
                .accessibilityLabel(pending > 0 ? "Уведомления, новых: \(pending)" : "Уведомления")

                Button {
                    router.sheet = .account
                } label: {
                    Image(systemName: "person.fill")
                        .frame(width: 44, height: 44)
                }
                .accessibilityLabel("Аккаунт")
                .accessibilityIdentifier("account-button")
            }
            .font(.system(size: 17, weight: .semibold))
            .foregroundStyle(.primary)
            .padding(.horizontal, 6)
            .glassCapsule()
        }
        .padding(.horizontal, AppSpacing.standard)
    }

    /// Installations ready to install and not cleared from the bell: the only notifications the app has.
    private var pending: Int {
        installations?.pendingNotifications.count ?? 0
    }
}
