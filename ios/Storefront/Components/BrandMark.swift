import SwiftUI

/// The Ru App Store logo.
struct BrandMark: View {
    var size: Double = 40

    var body: some View {
        Image(.brandLogo)
            .resizable()
            .scaledToFit()
            .frame(width: size, height: size)
            .accessibilityLabel("Ru App Store")
    }
}

#Preview {
    BrandMark(size: 80)
}
