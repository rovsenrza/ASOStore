import SwiftUI

/// The Ru AppStore logo.
struct BrandMark: View {
    var size: Double = 40

    var body: some View {
        Image(.brandLogo)
            .resizable()
            .scaledToFit()
            .frame(width: size, height: size)
            .clipShape(Circle())
            .accessibilityLabel("Ru AppStore")
    }
}

#Preview {
    BrandMark(size: 80)
}
