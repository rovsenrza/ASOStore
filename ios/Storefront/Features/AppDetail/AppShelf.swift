import SwiftUI

/// A titled row of app cards on the app page («Похожие приложения», «Ещё от издателя»).
/// Draws nothing when there are no apps.
struct AppShelf: View {
    let title: String
    let apps: [StoreApp]
    /// Names the shelf in the cards' routes (see `AppRoute`).
    let source: String
    /// Side margin of the page (see `AppDetailLayout.sideInset`).
    let inset: CGFloat

    var body: some View {
        if !apps.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                Text(title)
                    .detailSectionTitle()
                    .padding(.horizontal, inset)

                ScrollView(.horizontal) {
                    LazyHStack(alignment: .top, spacing: 14) {
                        ForEach(apps) { app in
                            AppShelfCard(app: app, source: source)
                        }
                    }
                    .scrollTargetLayoutCompat()
                }
                .viewAlignedScrolling()
                .scrollIndicators(.hidden)
                .horizontalContentMargins(inset)
            }
        }
    }
}
