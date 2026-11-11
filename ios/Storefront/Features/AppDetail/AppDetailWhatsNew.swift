import SwiftUI

/// «Что нового»: the latest version, when it came out and its notes, then earlier versions
/// behind «История версий». A version without notes is one line, not an empty block.
struct AppDetailWhatsNew: View {
    let app: StoreApp

    private var summary: String? {
        AppDetailFormat.versionLine(version: app.version, releasedAt: app.updatedAt)
    }

    var body: some View {
        let earlier = app.earlierVersions
        // Nothing to say without notes, a date or a history: a bare version number is in the facts already.
        if !app.whatsNew.isEmpty || app.updatedAt != nil || !earlier.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                Text("Что нового")
                    .detailSectionTitle()
                if let summary {
                    Text(summary)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                if !app.whatsNew.isEmpty {
                    ExpandableText(text: app.whatsNew)
                }
                if !earlier.isEmpty {
                    VersionHistory(versions: earlier)
                        .padding(.top, 4)
                }
            }
        }
    }
}
