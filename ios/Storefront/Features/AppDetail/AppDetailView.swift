import SwiftUI

/// App page. Opens with the summary the list already had and fills in the
/// full detail from the API (description, release notes, screenshots).
struct AppDetailView: View {
    @Environment(\.catalog) private var catalog
    @State private var app: StoreApp
    @State private var loadError: APIError?
    @State private var isStale = false
    @State private var expandsDescription = false

    init(app: StoreApp) {
        _app = State(initialValue: app)
    }

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 26) {
                if isStale {
                    StaleDataBanner()
                }
                appHeader
                if let loadError {
                    errorNotice(loadError)
                }
                Divider()
                metadata
                Divider()
                screenshots
                about
                whatsNew
                information
            }
            .padding(.horizontal, AppSpacing.standard)
            .padding(.bottom, 40)
        }
        .background(AppPalette.canvas)
        .navigationTitle(app.name)
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .refreshable { await load() }
    }

    private func load() async {
        do {
            let detail = try await catalog.app(id: app.id)
            app = StoreApp(detail.value)
            isStale = detail.isStale
            loadError = nil
        } catch is CancellationError {
            return
        } catch let error as APIError {
            loadError = error
        } catch {
            loadError = APIError(code: .internal)
        }
    }

    private func errorNotice(_ error: APIError) -> some View {
        HStack {
            Label(error.code == .offline || error.code == .networkError ? "Нет подключения" : "Не удалось загрузить описание", systemImage: "exclamationmark.triangle")
                .font(.footnote)
                .foregroundStyle(.secondary)
            Spacer()
            Button("Повторить") {
                Task { await load() }
            }
            .font(.footnote.bold())
        }
    }

    private var appHeader: some View {
        HStack(alignment: .top, spacing: 18) {
            AppIconView(app: app, size: 118)

            VStack(alignment: .leading, spacing: 6) {
                Text(app.name)
                    .font(.title2.bold())
                Text(app.subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
                Text(app.developer)
                    .font(.subheadline)
                    .foregroundStyle(AppPalette.accent)

                Spacer(minLength: 8)
                AppActionButton(app: app)
            }
            .frame(minHeight: 118, alignment: .topLeading)
        }
        .padding(.top, 8)
    }

    private var metadata: some View {
        HStack(spacing: 0) {
            metadataItem(value: app.version.isEmpty ? "—" : app.version, label: "ВЕРСИЯ", detail: "Текущая")
            Divider().frame(height: 52)
            metadataItem(value: app.ageRating, label: "ВОЗРАСТ", detail: "Лет")
            Divider().frame(height: 52)
            metadataItem(value: app.size, label: "РАЗМЕР", detail: "Приложение")
        }
        .frame(maxWidth: .infinity)
    }

    private func metadataItem(value: String, label: String, detail: String) -> some View {
        VStack(spacing: 3) {
            Text(label)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.tertiary)
            Text(value)
                .font(.title3.bold())
                .foregroundStyle(.secondary)
                .lineLimit(1)
                .minimumScaleFactor(0.7)
            Text(detail)
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
        .frame(maxWidth: .infinity)
    }

    @ViewBuilder
    private var screenshots: some View {
        if !app.screenshots.isEmpty || !app.screenshotTitles.isEmpty {
            VStack(alignment: .leading, spacing: 14) {
                Text("Предпросмотр")
                    .font(.title2.bold())

                ScrollView(.horizontal) {
                    LazyHStack(spacing: 12) {
                        if app.screenshots.isEmpty {
                            ForEach(Array(app.screenshotTitles.enumerated()), id: \.offset) { index, title in
                                ScreenshotMockView(app: app, title: title, index: index)
                            }
                        } else {
                            ForEach(Array(app.screenshots.enumerated()), id: \.offset) { index, url in
                                AsyncImage(url: url) { phase in
                                    if let image = phase.image {
                                        image.resizable().scaledToFit()
                                    } else {
                                        AppPalette.elevated
                                    }
                                }
                                .frame(width: 248, height: 470)
                                .clipShape(.rect(cornerRadius: 28))
                                .accessibilityLabel("Снимок экрана \(index + 1) из \(app.screenshots.count)")
                            }
                        }
                    }
                }
                .scrollIndicators(.hidden)
                .contentMargins(.horizontal, AppSpacing.standard, for: .scrollContent)
                .padding(.horizontal, -AppSpacing.standard)
            }
        }
    }

    @ViewBuilder
    private var about: some View {
        if !app.description.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                Text("Об этом приложении")
                    .font(.title2.bold())
                Text(app.description)
                    .font(.body)
                    .lineSpacing(3)
                    .lineLimit(expandsDescription ? nil : 4)
                Button(expandsDescription ? "Свернуть" : "Ещё") {
                    expandsDescription.toggle()
                }
                .font(.body)
            }
        }
    }

    @ViewBuilder
    private var whatsNew: some View {
        if !app.whatsNew.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    Text("Что нового")
                        .font(.title2.bold())
                    Spacer()
                    Text("Версия \(app.version)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Text(app.whatsNew)
                    .font(.body)
            }
            .padding(.top, 4)
        }
    }

    private var information: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Информация")
                .font(.title2.bold())
                .padding(.bottom, 8)
            informationRow(title: "Продавец", value: app.developer)
            informationRow(title: "Категория", value: app.category)
            informationRow(title: "Совместимость", value: app.minIOSVersion.map { "iOS \($0) и новее" } ?? "iPhone")
            informationRow(title: "Возраст", value: app.ageRating)
            if let supportURL = app.supportURL {
                linkRow(title: "Поддержка", url: supportURL)
            }
            if let privacyURL = app.privacyURL {
                linkRow(title: "Конфиденциальность", url: privacyURL)
            }
        }
    }

    private func informationRow(title: String, value: String) -> some View {
        HStack {
            Text(title).foregroundStyle(.secondary)
            Spacer()
            Text(value).multilineTextAlignment(.trailing)
        }
        .font(.subheadline)
        .padding(.vertical, 12)
        .overlay(alignment: .bottom) { Divider() }
    }

    private func linkRow(title: String, url: URL) -> some View {
        Link(destination: url) {
            HStack {
                Text(title).foregroundStyle(.secondary)
                Spacer()
                Text("Открыть")
                Image(systemName: "chevron.right")
                    .font(.caption.bold())
                    .foregroundStyle(.tertiary)
            }
            .font(.subheadline)
            .padding(.vertical, 12)
            .overlay(alignment: .bottom) { Divider() }
        }
    }
}

#if DEBUG
#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    NavigationStack {
        AppDetailView(app: MockCatalog.featured)
    }
    .environment(\.catalog, CatalogRepository(api: api, cache: .catalog))
}
#endif
