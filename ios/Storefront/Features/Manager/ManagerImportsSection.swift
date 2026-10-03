import SwiftUI

/// «Мои импорты»: uploads running on this device, then the customer's imports from the server.
struct ManagerImportsSection: View {
    @Environment(ImportCenter.self) private var imports: ImportCenter?

    var body: some View {
        if let imports, !(imports.uploads.isEmpty && imports.visibleImports.isEmpty) {
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    Text("Мои импорты")
                        .font(.title2.weight(.bold))
                    Spacer()
                    Text("\(imports.uploads.count + imports.visibleImports.count)")
                        .font(.headline)
                        .foregroundStyle(.secondary)
                }
                .padding(.horizontal, AppSpacing.standard)

                LazyVStack(spacing: 0) {
                    ForEach(imports.uploads) { upload in
                        UploadRow(upload: upload)
                        Divider().padding(.leading, 76)
                    }
                    ForEach(imports.visibleImports) { item in
                        ImportRow(item: item)
                            .transition(.opacity.combined(with: .move(edge: .top)))
                        Divider().padding(.leading, 76)
                    }
                }
                .padding(.horizontal, AppSpacing.standard)
                .animation(Motion.state, value: imports.visibleImports)
            }
        }
    }
}

private struct ImportIcon: View {
    var symbol = "shippingbox.fill"
    var tint: Color = AppPalette.accent

    var body: some View {
        Image(systemName: symbol)
            .font(.title2)
            .foregroundStyle(.white)
            .frame(width: 62, height: 62)
            .background(tint.gradient, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .accessibilityHidden(true)
    }
}

/// A file being sent from this device.
private struct UploadRow: View {
    let upload: ImportCenter.Upload
    @Environment(ImportCenter.self) private var imports: ImportCenter?

    var body: some View {
        HStack(spacing: 14) {
            ImportIcon(symbol: "arrow.up.doc.fill")

            VStack(alignment: .leading, spacing: 3) {
                Text(upload.name)
                    .font(.body.weight(.medium))
                    .lineLimit(1)
                Text(status)
                    .font(.footnote.weight(.medium))
                    .foregroundStyle(isFailed ? .red : .secondary)
                    .lineLimit(2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)

            if isFailed {
                Button("Повторить") { imports?.retry(upload.id) }
                    .font(.subheadline.weight(.bold))
                    .padding(.horizontal, 14)
                    .frame(minHeight: 34)
                    .glassCapsule()
                    .buttonStyle(PressableStyle(scale: 0.94))
            } else {
                HStack(spacing: 7) {
                    ProgressRing(progress: upload.progress, lineWidth: 2.5)
                        .frame(width: 15, height: 15)
                    AnimatedPercent(value: upload.progress)
                        .font(.footnote.weight(.bold))
                }
                .foregroundStyle(AppPalette.accent)
                .tint(AppPalette.accent)
                .padding(.horizontal, 12)
                .frame(minWidth: 88, minHeight: 34)
                .glassCapsule()
                .animation(.linear(duration: 0.3), value: upload.progress)
            }
        }
        .frame(minHeight: 82)
        .contextMenu {
            Button("Отменить импорт", systemImage: "xmark", role: .destructive) {
                Task { await imports?.cancel(upload.id) }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityAction(named: "Отменить импорт") {
            Task { await imports?.cancel(upload.id) }
        }
    }

    private var isFailed: Bool {
        if case .failed = upload.phase { true } else { false }
    }

    private var status: String {
        switch upload.phase {
        case .checking: "Подготовка файла…"
        case .uploading: "Загрузка на сервер"
        case .finishing: "Завершение загрузки…"
        case .failed(let reason): reason
        }
    }
}

/// One import on the server: downloading, being checked, ready to install, or failed.
private struct ImportRow: View {
    let item: ImportDTO
    @Environment(ImportCenter.self) private var imports: ImportCenter?
    @Environment(InstallationCoordinator.self) private var installations: InstallationCoordinator?
    @State private var confirmsDelete = false
    @State private var isWorking = false

    private var installState: InstallState? {
        installations?.installations[item.id]?.installState
    }

    var body: some View {
        HStack(spacing: 14) {
            ImportIcon(symbol: item.isFailed ? "exclamationmark.triangle.fill" : "shippingbox.fill",
                       tint: item.isFailed ? .orange : AppPalette.accent)
                .overlay(alignment: .bottomTrailing) {
                    if installState == .delivered {
                        Image(systemName: "checkmark.circle.fill")
                            .font(.system(size: 20))
                            .foregroundStyle(.white, AppPalette.success)
                            .offset(x: 5, y: 5)
                    }
                }

            VStack(alignment: .leading, spacing: 3) {
                Text(item.name)
                    .font(.body.weight(.medium))
                    .lineLimit(1)
                if !details.isEmpty {
                    Text(details)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                Text(statusText)
                    .font(.footnote.weight(.medium))
                    .foregroundStyle(statusColor)
                    .lineLimit(3)
            }
            .frame(maxWidth: .infinity, alignment: .leading)

            trailing
        }
        .frame(minHeight: 82)
        .contentShape(Rectangle())
        .contextMenu {
            Button("Удалить импорт", systemImage: "trash", role: .destructive) { confirmsDelete = true }
        }
        .confirmationDialog("Удалить «\(item.name)»?", isPresented: $confirmsDelete, titleVisibility: .visible) {
            Button("Удалить", role: .destructive) {
                Task { await imports?.delete(item) }
            }
        } message: {
            Text("Файл будет удалён с сервера. Уже установленное приложение останется на iPhone, но переустановить его отсюда будет нельзя.")
        }
        .accessibilityElement(children: .combine)
        .accessibilityAction(named: "Удалить импорт") { confirmsDelete = true }
    }

    @ViewBuilder
    private var trailing: some View {
        if case .preparing(let progress) = installState {
            SmoothedProgress(progress: progress) { shown, _ in
                HStack(spacing: 7) {
                    ProgressRing(progress: shown, lineWidth: 2.5)
                        .frame(width: 15, height: 15)
                    AnimatedPercent(value: shown)
                        .font(.footnote.weight(.bold))
                }
                .foregroundStyle(AppPalette.accent)
                .tint(AppPalette.accent)
                .padding(.horizontal, 12)
                .frame(minWidth: 88, minHeight: 34)
                .glassCapsule()
            }
        } else if item.installable {
            Button {
                Task { await install() }
            } label: {
                Group {
                    if isWorking {
                        ProgressView().controlSize(.small)
                    } else {
                        Text("Установить")
                    }
                }
                .font(.subheadline.weight(.bold))
                .foregroundStyle(installState == .readyToInstall ? .white : AppPalette.accent)
                .padding(.horizontal, 16)
                .frame(minWidth: 88, minHeight: 34)
                .modifier(InstallFace(ready: installState == .readyToInstall))
            }
            .buttonStyle(PressableStyle(scale: 0.94))
            .disabled(isWorking)
            .accessibilityLabel("Установить \(item.name)")
        } else if item.isProcessing {
            ProgressView()
                .frame(minWidth: 44, minHeight: 34)
                .accessibilityLabel("Проверяется")
        } else if item.isFailed {
            Button {
                confirmsDelete = true
            } label: {
                Image(systemName: "trash")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.red)
                    .frame(width: 44, height: 34)
                    .glassCapsule()
            }
            .buttonStyle(PressableStyle(scale: 0.94))
            .accessibilityLabel("Удалить импорт")
        }
    }

    private func install() async {
        // Ready on the server already: open the install link, like the catalog CTA.
        if installState == .readyToInstall, let installations {
            let app = StoreApp(
                id: item.id, name: item.name, subtitle: "", category: "", developer: "",
                systemImage: "shippingbox.fill", artwork: .derived(from: item.id),
                installState: .readyToInstall, iconURL: nil
            )
            await installations.act(on: app)
            return
        }
        isWorking = true
        await imports?.install(item)
        isWorking = false
    }

    private var details: String {
        [item.version.map { "v\($0)" }, item.sizeBytes.map { ByteCountFormatter.string(fromByteCount: $0, countStyle: .file) }]
            .compactMap { $0 }.joined(separator: " · ")
    }

    private var statusText: String {
        if item.isFailed {
            return ImportCenter.failureText(for: item)
        }
        switch installState {
        case .preparing: return "Подписываем для вашего iPhone"
        case .readyToInstall: return "Готово к установке"
        case .delivered: return "Загружено — проверьте экран «Домой»"
        default: break
        }
        switch item.status {
        case "DOWNLOADING": return "Сервер скачивает файл…"
        case "UPLOADING": return "Загрузка не завершена"
        case "PUBLISHED", "PROVENANCE_REVIEW", "READY": return "Проверено"
        default: return "Проверяем файл…"
        }
    }

    private var statusColor: Color {
        if item.isFailed { return .red }
        if item.installable || installState == .readyToInstall { return AppPalette.success }
        return .secondary
    }
}

private struct InstallFace: ViewModifier {
    let ready: Bool

    func body(content: Content) -> some View {
        if ready {
            content.glassCapsule(tint: AppPalette.success)
        } else {
            content.glassCapsule()
        }
    }
}
