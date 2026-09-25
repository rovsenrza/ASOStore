import SwiftUI

struct ScreenshotMockView: View {
    let app: StoreApp
    let title: String
    let index: Int

    var body: some View {
        VStack(spacing: 18) {
            Text(title)
                .font(.title2.bold())
                .multilineTextAlignment(.center)
                .padding(.horizontal)

            mockInterface

            Spacer(minLength: 0)
        }
        .padding(.top, 28)
        .padding(.horizontal, 18)
        .frame(width: 248, height: 470)
        .background(backgroundGradient)
        .foregroundStyle(.white)
        .clipShape(.rect(cornerRadius: 28))
        .overlay {
            RoundedRectangle(cornerRadius: 28)
                .stroke(.white.opacity(0.14), lineWidth: 1)
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Снимок экрана: \(title)")
    }

    private var mockInterface: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Circle().frame(width: 30, height: 30)
                RoundedRectangle(cornerRadius: 5).frame(width: 88, height: 10)
                Spacer()
                Circle().frame(width: 24, height: 24)
            }

            ForEach(0..<(index + 2), id: \.self) { row in
                RoundedRectangle(cornerRadius: 14)
                    .fill(.white.opacity(row == 0 ? 0.3 : 0.17))
                    .frame(height: row == 0 ? 92 : 54)
            }
        }
        .padding(16)
        .background(.black.opacity(0.14))
        .clipShape(.rect(cornerRadius: 22))
    }

    private var backgroundGradient: LinearGradient {
        let color = app.artwork.color
        return LinearGradient(colors: [color.opacity(0.72), color], startPoint: .topLeading, endPoint: .bottomTrailing)
    }
}
