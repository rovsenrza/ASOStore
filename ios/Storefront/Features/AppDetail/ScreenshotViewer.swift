import SwiftUI

/// Screenshots full screen, swiping between them; zooms out of the one tapped.
struct ScreenshotViewer: View {
    let urls: [URL]
    @State var selection: Int
    /// The page that is zoomed in, if any: paging is off until it zooms back out.
    @State private var zoomedPage: Int?
    @Environment(\.dismiss) private var dismiss
    @ScaledMetric(relativeTo: .body) private var scaledCloseSize = 44.0

    var body: some View {
        ZStack(alignment: .topTrailing) {
            Color.black.ignoresSafeArea()
            TabView(selection: $selection) {
                ForEach(Array(urls.enumerated()), id: \.offset) { index, url in
                    ZoomableScreenshot(
                        url: url,
                        label: "Снимок экрана \(index + 1) из \(urls.count)",
                        isCurrent: selection == index
                    ) { zoomed in
                        if zoomed {
                            zoomedPage = index
                        } else if zoomedPage == index {
                            zoomedPage = nil
                        }
                    }
                    .tag(index)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: urls.count > 1 ? .always : .never))
            .scrollDisabled(zoomedPage == selection)

            Button {
                dismiss()
            } label: {
                Label("Закрыть", systemImage: "xmark")
                    .labelStyle(.iconOnly)
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(.white)
                    .frame(width: min(scaledCloseSize, 60), height: min(scaledCloseSize, 60))
                    .glassCircle()
            }
            .padding(16)
        }
        // Always a dark room: the close button's glass and the page dots stay readable over
        // bright screenshots in light mode too.
        .environment(\.colorScheme, .dark)
        .statusBarHidden()
        .accessibilityAction(.escape) { dismiss() }
    }
}
