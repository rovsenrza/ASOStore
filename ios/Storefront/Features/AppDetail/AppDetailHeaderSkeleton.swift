import SwiftUI

/// The header of a page opened from a link, before the app's name and icon are known:
/// the same shapes as `AppDetailHeader`, so nothing jumps when they arrive.
struct AppDetailHeaderSkeleton: View {
    /// False once loading has failed: the shapes stay, the sweep stops.
    var isLoading = true

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            HStack(alignment: .bottom, spacing: 16) {
                RoundedRectangle(cornerRadius: AppDetailHeader.iconSize * 0.22, style: .continuous)
                    .frame(width: AppDetailHeader.iconSize, height: AppDetailHeader.iconSize)
                VStack(alignment: .leading, spacing: 10) {
                    Capsule().frame(width: 170, height: 20)
                    Capsule().frame(width: 130, height: 13)
                    Capsule().frame(width: 90, height: 13)
                }
                .padding(.bottom, 8)
                Spacer(minLength: 0)
            }
            Capsule().frame(height: 52)
        }
        .foregroundStyle(AppPalette.ctaFill)
        .modifier(SkeletonSweep(isActive: isLoading))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(isLoading ? "Загрузка приложения" : "Приложение не загружено")
    }
}
