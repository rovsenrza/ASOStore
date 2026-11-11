import SwiftUI

/// «Об этом приложении»: the description, folded when long. Many listings have none yet:
/// they get one quiet line instead of a hole in the page.
struct AppDetailAbout: View {
    let description: String

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Об этом приложении")
                .detailSectionTitle()
            if description.isEmpty {
                Label("Описание скоро появится", systemImage: "text.alignleft")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .accessibilityIdentifier("detail-description-placeholder")
            } else {
                ExpandableText(text: description)
            }
        }
    }
}
