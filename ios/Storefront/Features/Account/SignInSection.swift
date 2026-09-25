import SwiftUI

/// In-app sign-in, a fallback to the web flow (IMPLEMENTATION_PLAN P2-IOS-01).
struct SignInSection: View {
    @Environment(SessionStore.self) private var session
    @Environment(\.apiClient) private var apiClient
    @State private var email = ""
    @State private var password = ""
    @State private var isSubmitting = false
    @State private var error: APIError?
    @State private var portalURL: URL?

    var body: some View {
        Section {
            if session.state == .expired {
                Label("Сеанс истёк. Войдите снова.", systemImage: "clock.badge.exclamationmark")
                    .foregroundStyle(.orange)
            }

            TextField("Эл. почта", text: $email)
                .textContentType(.username)
                .keyboardType(.emailAddress)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()

            SecureField("Пароль", text: $password)
                .textContentType(.password)

            if let error {
                Text(message(for: error))
                    .font(.footnote)
                    .foregroundStyle(.red)
            }

            Button {
                Task { await submit() }
            } label: {
                if isSubmitting {
                    ProgressView()
                } else {
                    Text("Войти")
                        .bold()
                }
            }
            .disabled(email.isEmpty || password.isEmpty || isSubmitting)
        } header: {
            Text("Вход")
        } footer: {
            if let portalURL {
                Text("Нет аккаунта? [Создайте его на сайте](\(portalURL.appending(path: "register.html").absoluteString)).")
            }
        }
        .task {
            portalURL = await apiClient.environment.portalURL
        }
    }

    private func submit() async {
        isSubmitting = true
        error = nil
        defer { isSubmitting = false }

        do {
            try await session.signIn(email: email, password: password)
            password = ""
        } catch let apiError as APIError {
            error = apiError
        } catch {
            self.error = APIError(code: .internal)
        }
    }

    private func message(for error: APIError) -> String {
        switch error.code {
        case .invalidCredentials: "Неверная эл. почта или пароль."
        case .accountSuspended: "Аккаунт заблокирован. Обратитесь в поддержку."
        case .rateLimited: "Слишком много попыток. Подождите минуту."
        case .offline, .networkError: "Нет связи с сервером. Проверьте интернет."
        default: error.message.isEmpty ? "Не удалось войти. Повторите позже." : error.message
        }
    }
}

#Preview {
    let api = APIClient.configured(environment: APIEnvironment(baseURL: URL(string: "http://127.0.0.1:8000/api/v1")!, mode: .mock))
    List { SignInSection() }
        .environment(SessionStore(api: api))
        .environment(\.apiClient, api)
}
