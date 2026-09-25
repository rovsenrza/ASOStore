import SwiftUI

struct FeaturedAppCard: View {
    let app: StoreApp
    var eyebrow = "История дня"

    var body: some View {
        NavigationLink(value: app) {
            VStack(alignment: .leading, spacing: AppSpacing.standard) {
                Text(eyebrow.uppercased())
                    .font(.footnote)
                    .bold()
                    .foregroundStyle(.white.opacity(0.8))

                Spacer()

                AppIconView(app: app, size: 76)

                VStack(alignment: .leading, spacing: AppSpacing.compact / 2) {
                    Text(app.name)
                        .font(.largeTitle)
                        .bold()

                    Text(app.subtitle)
                        .font(.headline)
                        .foregroundStyle(.white.opacity(0.82))
                }

                Text("Подробнее")
                    .font(.subheadline.bold())
                    .foregroundStyle(app.artwork.color)
                    .padding(.horizontal, 16)
                    .frame(height: 34)
                    .background(.white)
                    .clipShape(.capsule)
            }
            .padding(AppSpacing.section)
            .frame(width: 340, height: 400, alignment: .leading)
            .background(app.artwork.color.gradient)
            .foregroundStyle(.white)
            .clipShape(.rect(cornerRadius: 24))
            .accessibilityElement(children: .contain)
        }
        .buttonStyle(.plain)
    }
}
