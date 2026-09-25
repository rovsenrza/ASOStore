import SwiftUI

struct CategoryCard: View {
    let category: StoreCategory

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Image(systemName: category.systemImage)
                .font(.title2)
                .foregroundStyle(.white)
                .frame(width: 44, height: 44)
                .background(artworkColor.gradient)
                .clipShape(.rect(cornerRadius: 12))

            Spacer()

            Text(category.title)
                .font(.headline)
            Text(category.subtitle)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding(16)
        .frame(width: 190, height: 145, alignment: .leading)
        .background(AppPalette.elevated)
        .clipShape(.rect(cornerRadius: 18))
    }

    private var artworkColor: Color {
        category.artwork.color
    }
}
