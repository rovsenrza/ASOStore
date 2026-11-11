import SwiftUI

/// A compact reminder that the subscription is about to end (or has), with «Продлить».
/// Closing it hides it for the rest of the day, here and in the account sheet alike.
struct SubscriptionExpiryBanner: View {
    @EnvironmentObject private var session: SessionStore
    @Environment(\.apiClient) private var apiClient
    @Environment(\.openURL) private var openURL
    @AppStorage(SubscriptionExpiryNotice.dismissedAtKey) private var dismissedAt = 0.0

    var body: some View {
        if let notice {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: notice.isExpired ? "exclamationmark.circle.fill" : "clock.badge.exclamationmark")
                    .font(.title3)
                    .foregroundStyle(notice.isExpired ? .red : .orange)
                    .accessibilityHidden(true)

                VStack(alignment: .leading, spacing: 8) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(notice.title)
                            .font(.subheadline.weight(.semibold))
                        Text(notice.detail)
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                    .accessibilityElement(children: .combine)

                    Button("Продлить") {
                        if let url = apiClient.environment.renewURL { openURL(url) }
                    }
                    .font(.subheadline.weight(.bold))
                    .buttonStyle(.borderedProminent)
                    .buttonBorderShape(.capsule)
                    .controlSize(.small)
                    .accessibilityIdentifier("subscription-renew")
                }
                .frame(maxWidth: .infinity, alignment: .leading)

                Button {
                    withAnimation(Motion.state) { dismissedAt = Date.now.timeIntervalSinceReferenceDate }
                } label: {
                    Image(systemName: "xmark")
                        .font(.footnote.weight(.bold))
                        .foregroundStyle(.secondary)
                        .frame(width: 32, height: 32)
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Скрыть до завтра")
            }
            .padding(14)
            .background(AppPalette.card, in: .rect(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.25), lineWidth: 0.5))
            .transition(.opacity)
        }
    }

    private var notice: SubscriptionExpiryNotice? {
        let closed = dismissedAt > 0 ? Date(timeIntervalSinceReferenceDate: dismissedAt) : nil
        guard !SubscriptionExpiryNotice.isDismissed(at: closed) else { return nil }
        return SubscriptionExpiryNotice(subscription: session.user?.subscription)
    }
}
