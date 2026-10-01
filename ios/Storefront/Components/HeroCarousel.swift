import SwiftUI

/// Editor's picks as full-width cards with the app's banner, paging sideways. Cards off
/// centre shrink back and their artwork drifts against the swipe.
struct HeroCarousel: View {
    let apps: [StoreApp]
    @State private var current: String?

    var body: some View {
        VStack(spacing: 12) {
            ScrollView(.horizontal) {
                LazyHStack(spacing: 12) {
                    ForEach(apps) { app in
                        HeroCard(app: app)
                            .containerRelativeFrame(.horizontal)
                            .scrollTransition(axis: .horizontal) { content, phase in
                                content
                                    .scaleEffect(phase.isIdentity ? 1 : 0.93)
                                    .opacity(phase.isIdentity ? 1 : 0.7)
                            }
                            .id(app.id)
                    }
                }
                .scrollTargetLayout()
            }
            .scrollTargetBehavior(.viewAligned)
            .scrollIndicators(.hidden)
            .scrollPosition(id: $current)
            .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)

            if apps.count > 1 {
                pageDots
            }
        }
    }

    /// Where the swipe is: the current card's dot stretches into a pill.
    private var pageDots: some View {
        let selected = current ?? apps.first?.id
        return HStack(spacing: 6) {
            ForEach(apps) { app in
                let isCurrent = app.id == selected
                Capsule()
                    .fill(isCurrent ? AnyShapeStyle(.primary) : AnyShapeStyle(.tertiary))
                    .frame(width: isCurrent ? 18 : 6, height: 6)
            }
        }
        .animation(Motion.select, value: current)
        .accessibilityHidden(true)
    }
}

struct HeroCard: View {
    /// Compact enough to leave the shelves below in view (the banner picture is 16:10).
    static let height = 224.0

    let app: StoreApp
    @Environment(\.zoomNamespace) private var zoom

    private var route: AppRoute { AppRoute(app, from: "hero") }

    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            // The whole card opens the app; only the install capsule acts on its own.
            NavigationLink(value: route) {
                ZStack(alignment: .bottom) {
                    banner
                        .frame(height: Self.height)
                        .frame(maxWidth: .infinity)
                        .visualEffect { content, proxy in
                            // Parallax: the artwork lags behind the card while it pages.
                            let midX = proxy.frame(in: .scrollView(axis: .horizontal)).midX
                            let width = proxy.size.width
                            // Scaled so the drift never uncovers an edge.
                            return content
                                .scaleEffect(1.2)
                                .offset(x: width > 0 ? -(midX - width / 2) * 0.08 : 0)
                        }
                        .clipped()

                    HStack(spacing: 12) {
                        AppIconView(app: app, size: 50)
                            .overlay(RoundedRectangle(cornerRadius: 50 * 0.22, style: .continuous).strokeBorder(.white.opacity(0.3), lineWidth: 0.5))
                        VStack(alignment: .leading, spacing: 2) {
                            Text(app.name)
                                .font(.headline)
                                .lineLimit(1)
                            Text(app.subtitle.isEmpty ? app.category : app.subtitle)
                                .font(.subheadline)
                                .opacity(0.8)
                                .lineLimit(1)
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        // Room for the install capsule drawn on top.
                        Color.clear.frame(width: 104, height: 1)
                    }
                    .padding(14)
                    .background {
                        // Readable over any artwork, in light and dark mode alike.
                        LinearGradient(stops: [.init(color: .clear, location: 0), .init(color: .black.opacity(0.72), location: 1)], startPoint: .top, endPoint: .bottom)
                            .padding(.top, -40)
                    }
                }
                .contentShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
            }
            .buttonStyle(PressableStyle(scale: 0.98))

            AppActionButton(app: app, style: .overImage)
                .padding(.trailing, 14)
                .padding(.bottom, 22)
        }
        .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 28, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.3), lineWidth: 0.5))
        .zoomSource(route, in: zoom)
    }

    @ViewBuilder
    private var banner: some View {
        if let url = app.featureImageURL {
            CachedImage(url: url, maxPixel: ImagePixels.banner) { image in
                image.resizable().scaledToFill()
            } placeholder: {
                placeholder
            }
        } else {
            placeholder
        }
    }

    /// No banner yet: the app's colour with its symbol, never a fake picture.
    private var placeholder: some View {
        ZStack {
            LinearGradient(colors: [app.artwork.color, app.artwork.color.opacity(0.6), AppPalette.brandDeep], startPoint: .topLeading, endPoint: .bottomTrailing)
            Image(systemName: app.systemImage)
                .font(.system(size: 96, weight: .semibold))
                .foregroundStyle(.white.opacity(0.2))
                .offset(x: 90, y: -30)
        }
    }
}
