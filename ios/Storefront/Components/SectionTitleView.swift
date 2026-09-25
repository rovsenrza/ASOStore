import SwiftUI

struct SectionTitleView: View {
    let title: LocalizedStringKey
    let actionTitle: LocalizedStringKey?

    var body: some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title)
                .font(.title2)
                .bold()

            Spacer()

            if let actionTitle {
                Button(actionTitle) { }
                    .font(.subheadline)
            }
        }
    }
}
