import SwiftUI
import UniformTypeIdentifiers

/// The two ways in: an IPA from Files (or sent here from another app), or a link the server downloads.
struct ManagerImportCards: View {
    @Environment(ImportCenter.self) private var imports: ImportCenter?
    @State private var showsFilePicker = false
    @State private var showsLinkSheet = false

    var body: some View {
        VStack(spacing: AppSpacing.standard) {
            Button {
                showsFilePicker = true
            } label: {
                ManagerImportCard(
                    symbols: ["iphone", "folder.fill", "square.and.arrow.up"],
                    highlightedSymbol: 1,
                    title: "Импортировать IPA с устройства",
                    description: "Выберите IPA в Файлах или откройте его в Ru App Store из другого приложения через «Поделиться»."
                )
            }
            .buttonStyle(PressableStyle(scale: 0.98))
            .accessibilityHint("Откроет Файлы для выбора IPA.")

            Button {
                showsLinkSheet = true
            } label: {
                ManagerImportCard(
                    symbols: ["link", "cloud.fill", "externaldrive.fill.badge.icloud", "arrow.down.doc.fill"],
                    highlightedSymbol: 1,
                    title: "Импортировать IPA по ссылке",
                    description: "Вставьте прямую ссылку на IPA или ссылку Dropbox / Google Диска — сервер скачает файл сам."
                )
            }
            .buttonStyle(PressableStyle(scale: 0.98))
            .accessibilityHint("Откроет поле для ссылки.")

            Text("Импортируя файл, вы подтверждаете, что имеете право устанавливать это приложение. Импорт виден только вам.")
                .font(.footnote)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, AppSpacing.compact)
        }
        .padding(.horizontal, AppSpacing.standard)
        .disabled(imports == nil)
        .fileImporter(isPresented: $showsFilePicker, allowedContentTypes: [.ipa], allowsMultipleSelection: false) { result in
            if case .success(let urls) = result, let url = urls.first {
                imports?.importFile(at: url)
            }
        }
        .sheet(isPresented: $showsLinkSheet) {
            ImportLinkSheet()
        }
    }
}

extension UTType {
    /// Declared in Info.plist (UTImportedTypeDeclarations), so Files and the share sheet offer .ipa files to us.
    static let ipa = UTType(importedAs: "com.apple.itunes.ipa", conformingTo: .zip)
}

/// Paste a link; the server downloads and inspects the file, and the import appears in the list.
struct ImportLinkSheet: View {
    @Environment(ImportCenter.self) private var imports: ImportCenter?
    @Environment(\.dismiss) private var dismiss
    @State private var link = ""
    @State private var isSending = false
    @FocusState private var focused: Bool

    private var isValid: Bool {
        let text = link.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return text.hasPrefix("https://") && text.count > 10
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("https://…", text: $link, axis: .vertical)
                        .keyboardType(.URL)
                        .textContentType(.URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .focused($focused)
                        .lineLimit(1...4)
                    PasteButton(payloadType: String.self) { strings in
                        if let first = strings.first { link = first }
                    }
                    .labelStyle(.titleAndIcon)
                } header: {
                    Text("Ссылка на IPA")
                } footer: {
                    Text("Подойдёт прямая ссылка на файл .ipa или общедоступная ссылка Dropbox и Google Диска. Файл до 3 ГБ скачивается на сервере — приложение можно закрыть.")
                }
            }
            .navigationTitle("Импорт по ссылке")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Отмена") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    if isSending {
                        ProgressView()
                    } else {
                        Button("Импортировать") {
                            Task { await send() }
                        }
                        .disabled(!isValid)
                    }
                }
            }
            .onAppear { focused = true }
        }
        .presentationDetents([.medium, .large])
    }

    private func send() async {
        guard let imports else { return }
        isSending = true
        let accepted = await imports.importLink(link)
        isSending = false
        if accepted {
            dismiss()
        }
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
