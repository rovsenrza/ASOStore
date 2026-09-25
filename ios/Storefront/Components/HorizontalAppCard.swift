import SwiftUI

struct HorizontalAppCard: View {
    let app: StoreApp
    let eyebrow: String

    var body: some View {
        NavigationLink(value: app) {
            VStack(alignment: .leading, spacing: 0) {
                ZStack(alignment: .bottomLeading) {
                    LinearGradient(
                        colors: [artworkColor.opacity(0.7), artworkColor],
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )

                    Image(systemName: app.systemImage)
                        .font(.system(size: 74, weight: .medium))
                        .foregroundStyle(.white.opacity(0.18))
                        .offset(x: 180, y: -20)

                    VStack(alignment: .leading, spacing: 8) {
                        Text(eyebrow.uppercased())
                            .font(.caption.weight(.bold))
                            .foregroundStyle(.white.opacity(0.8))
                        Text(app.name)
                            .font(.title.bold())
                        Text(app.subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.white.opacity(0.84))
                            .lineLimit(2)
                    }
                    .padding(20)
                    .foregroundStyle(.white)
                }
                .frame(height: 220)

                HStack(spacing: 12) {
                    AppIconView(app: app, size: 46)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(app.name).font(.subheadline.weight(.semibold))
                        Text(app.category).font(.caption).foregroundStyle(.secondary)
                    }
                    Spacer()
                    Image(systemName: "chevron.right")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(.tertiary)
                }
                .padding(14)
                .background(AppPalette.elevated)
            }
            .clipShape(.rect(cornerRadius: 22))
        }
        .buttonStyle(.plain)
        .frame(width: 320)
    }

    private var artworkColor: Color {
        app.artwork.color
    }
}
