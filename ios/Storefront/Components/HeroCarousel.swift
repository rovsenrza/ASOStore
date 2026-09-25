import SwiftUI

/// Editor's picks as full-width cards with the app's banner, paging sideways.
struct HeroCarousel: View {
    let apps: [StoreApp]

    var body: some View {
        ScrollView(.horizontal) {
            LazyHStack(spacing: 12) {
                ForEach(apps) { app in
                    HeroCard(app: app)
                        .containerRelativeFrame(.horizontal)
                }
            }
            .scrollTargetLayout()
        }
        .scrollTargetBehavior(.viewAligned)
        .scrollIndicators(.hidden)
        .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
    }
}

struct HeroCard: View {
    let app: StoreApp

    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            // The whole card opens the app; only the install capsule acts on its own.
            NavigationLink(value: app) {
                ZStack(alignment: .bottom) {
                    banner
                        .frame(height: 300)
                        .frame(maxWidth: .infinity)
                        .clipped()

                    HStack(spacing: 12) {
                        AppIconView(app: app, size: 56)
                            .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(.white.opacity(0.25), lineWidth: 0.5))
                        VStack(alignment: .leading, spacing: 2) {
                            Text(app.name)
                                .font(.headline)
                                .lineLimit(1)
                            Text(app.category)
                                .font(.subheadline)
                                .opacity(0.85)
                                .lineLimit(1)
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        // Room for the install capsule drawn on top.
                        Color.clear.frame(width: 110, height: 1)
                    }
                    .padding(14)
                    .background {
                        // Readable over any artwork, in light and dark mode alike.
                        LinearGradient(colors: [.clear, .black.opacity(0.7)], startPoint: .top, endPoint: .bottom)
                    }
                }
            }
            .buttonStyle(.plain)

            AppActionButton(app: app, style: .overImage)
                .padding(.trailing, 14)
                .padding(.bottom, 24)
        }
        .clipShape(RoundedRectangle(cornerRadius: 26, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 26, style: .continuous).strokeBorder(AppPalette.separator.opacity(0.35), lineWidth: 0.5))
    }

    @ViewBuilder
    private var banner: some View {
        if let url = app.featureImageURL {
            AsyncImage(url: url) { phase in
                if let image = phase.image {
                    image.resizable().scaledToFill()
                } else {
                    placeholder
                }
            }
        } else {
            placeholder
        }
    }

    /// No screenshot yet: the app's colour with its symbol, never a fake picture.
    private var placeholder: some View {
        ZStack {
            LinearGradient(colors: [app.artwork.color, app.artwork.color.opacity(0.55), AppPalette.brandDeep], startPoint: .topLeading, endPoint: .bottomTrailing)
            Image(systemName: app.systemImage)
                .font(.system(size: 110, weight: .semibold))
                .foregroundStyle(.white.opacity(0.22))
                .offset(x: 70, y: -30)
        }
    }
}
