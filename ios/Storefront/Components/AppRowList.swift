import SwiftUI

/// Vertical list of app rows with separators.
struct AppRowList: View {
    let apps: [StoreApp]

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(apps) { app in
                StoreAppRow(app: app)
                    .padding(.vertical, 6)
                if app.id != apps.last?.id {
                    Divider().padding(.leading, 80)
                }
            }
        }
    }
}
