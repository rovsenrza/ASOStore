import SwiftUI

/// Renders every LoadState a screen can be in, with retry on each failure
/// (FULL_PLAN §11). Features supply only the loaded content.
struct StateContainerView<Value: Sendable, Content: View>: View {
    /// What to show while loading, shaped like the content on its way.
    enum Skeleton {
        case rows
        case feed
    }

    let state: LoadState<Value>
    let retry: () -> Void
    var skeleton: Skeleton?
    @ViewBuilder let content: (Value) -> Content

    var body: some View {
        switch state {
        case .loading:
            switch skeleton {
            case .rows:
                SkeletonRows()
                    .padding(.horizontal, AppSpacing.standard)
            case .feed:
                FeedSkeleton()
            case nil:
                ProgressView("Загрузка…")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        case .loaded(let value):
            content(value)
        case .empty:
            ContentUnavailableView("Здесь пока пусто", systemImage: "tray")
        case .offline:
            unavailable("Нет подключения", "wifi.slash", "Проверьте интернет и повторите попытку.")
        case .unauthorized:
            unavailable("Нужен вход", "person.crop.circle.badge.exclamationmark", "Войдите в аккаунт, чтобы продолжить.")
        case .expired:
            unavailable("Сеанс истёк", "clock.badge.exclamationmark", "Войдите снова, чтобы продолжить.")
        case .failed(let error):
            unavailable(
                "Что-то пошло не так",
                "exclamationmark.triangle",
                error.requestID.map { "Повторите попытку позже. Код запроса: \($0)" } ?? "Повторите попытку позже."
            )
        }
    }

    private func unavailable(_ title: String, _ systemImage: String, _ description: String) -> some View {
        ContentUnavailableView {
            Label(title, systemImage: systemImage)
        } description: {
            Text(description)
        } actions: {
            Button("Повторить", action: retry)
                .buttonStyle(.borderedProminent)
        }
    }
}
