import SwiftUI

struct ManagerImportCards: View {
    var body: some View {
        VStack(spacing: AppSpacing.standard) {
            ManagerImportCard(
                symbols: ["iphone", "folder.fill", "square.and.arrow.up"],
                highlightedSymbol: 1,
                title: "Импортировать IPA с устройства",
                description: "Выберите IPA в Файлах или отправьте его в Ru App Store из другого приложения."
            )

            ManagerImportCard(
                symbols: ["link", "cloud.fill", "externaldrive.fill.badge.icloud", "arrow.down.doc.fill"],
                highlightedSymbol: 1,
                title: "Импортировать IPA по ссылке",
                description: "Вставьте прямую ссылку на IPA или поддерживаемое облако, чтобы подготовить файл к подписанию."
            )
        }
        .padding(.horizontal, AppSpacing.standard)
    }
}

#Preview {
    ScrollView {
        ManagerImportCards()
            .padding(.vertical)
    }
    .background { BrandBackdrop() }
    .background(AppPalette.canvas)
}
