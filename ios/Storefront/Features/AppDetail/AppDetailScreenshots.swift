import SwiftUI

/// «Предпросмотр»: the screenshot gallery. Tapping one opens the full-screen viewer, which
/// zooms out of it. Draws nothing for an app without screenshots.
struct AppDetailScreenshots: View {
    let app: StoreApp
    /// Side margin of the page (see `AppDetailLayout.sideInset`).
    let inset: CGFloat
    let zoomNamespace: Namespace.ID
    @Binding var viewer: ScreenshotSelection?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.verticalSizeClass) private var verticalSizeClass

    /// A phone on its side has little height: smaller shots keep the gallery on one screen.
    private var shotSize: CGSize {
        verticalSizeClass == .compact ? CGSize(width: 144, height: 280) : CGSize(width: 236, height: 460)
    }

    var body: some View {
        if !app.screenshots.isEmpty || !app.screenshotTitles.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                Text("Предпросмотр")
                    .detailSectionTitle()
                    .padding(.horizontal, inset)

                ScrollView(.horizontal) {
                    LazyHStack(spacing: 12) {
                        if app.screenshots.isEmpty {
                            // Illustrative screens for previews; the catalog never sends titles.
                            ForEach(Array(app.screenshotTitles.enumerated()), id: \.offset) { index, title in
                                ScreenshotMockView(app: app, title: title, index: index)
                            }
                        } else {
                            ForEach(Array(app.screenshots.enumerated()), id: \.offset) { index, url in
                                shot(url, index: index)
                            }
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

    private func shot(_ url: URL, index: Int) -> some View {
        Button {
            viewer = ScreenshotSelection(index: index)
        } label: {
            ScreenshotImage(url: url)
                .frame(width: shotSize.width, height: shotSize.height)
                .clipShape(.rect(cornerRadius: 26, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 26, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.3), lineWidth: 0.5))
        }
        .buttonStyle(PressableStyle(scale: reduceMotion ? 1 : 0.97))
        .zoomTransitionSource(id: index, in: zoomNamespace)
        .scrollShrink(reduceMotion ? 1 : 0.94)
        .accessibilityLabel("Снимок экрана \(index + 1) из \(app.screenshots.count)")
        .accessibilityHint("Открыть на весь экран")
        .accessibilityAddTraits(.isImage)
    }
}
