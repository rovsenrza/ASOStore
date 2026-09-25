<?php

namespace App\Enums;

/**
 * Stable machine-readable error codes (IMPLEMENTATION_PLAN §5.2).
 *
 * Clients map the code to their own copy; message() is only a Russian fallback.
 * Never rename a case once a client ships — add a new one instead.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case SessionExpired = 'SESSION_EXPIRED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case TotpRequired = 'TOTP_REQUIRED';
    case TotpInvalid = 'TOTP_INVALID';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case IllegalStateTransition = 'ILLEGAL_STATE_TRANSITION';
    case RateLimited = 'RATE_LIMITED';
    case ActivationInvalid = 'ACTIVATION_INVALID';
    case ActivationAlreadyUsed = 'ACTIVATION_ALREADY_USED';
    case EnrollmentChallengeExpired = 'ENROLLMENT_CHALLENGE_EXPIRED';
    case DeviceNotEligible = 'DEVICE_NOT_ELIGIBLE';
    case DeviceLimitReached = 'DEVICE_LIMIT_REACHED';
    case DeviceOwnedElsewhere = 'DEVICE_OWNED_ELSEWHERE';
    case EnrollmentPayloadInvalid = 'ENROLLMENT_PAYLOAD_INVALID';
    case AppleNotConnected = 'APPLE_NOT_CONNECTED';
    case ClaimInvalid = 'CLAIM_INVALID';
    case DevicePendingApple = 'DEVICE_PENDING_APPLE';
    case QuotaExhausted = 'QUOTA_EXHAUSTED';
    case NoEligibleTeam = 'NO_ELIGIBLE_TEAM';
    case ArtifactNotInstallable = 'ARTIFACT_NOT_INSTALLABLE';
    case IncompatibleDevice = 'INCOMPATIBLE_DEVICE';
    case InstallTokenExpired = 'INSTALL_TOKEN_EXPIRED';
    case DuplicateArtifact = 'DUPLICATE_ARTIFACT';
    case UploadIncomplete = 'UPLOAD_INCOMPLETE';
    case UploadCorrupt = 'UPLOAD_CORRUPT';
    case VersionExists = 'VERSION_EXISTS';
    case EncryptedBinary = 'ENCRYPTED_BINARY';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';
    case AppleUnavailable = 'APPLE_UNAVAILABLE';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case Internal = 'INTERNAL';

    public function httpStatus(): int
    {
        return match ($this) {
            self::ValidationFailed, self::ActivationInvalid, self::EnrollmentChallengeExpired,
            self::InvalidCredentials, self::TotpInvalid, self::EnrollmentPayloadInvalid, self::ClaimInvalid => 422,
            self::Unauthenticated, self::SessionExpired, self::TotpRequired => 401,
            self::Forbidden, self::AccountSuspended, self::DeviceNotEligible, self::IncompatibleDevice => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InstallTokenExpired => 410,
            self::Conflict, self::IllegalStateTransition, self::ActivationAlreadyUsed, self::DevicePendingApple,
            self::QuotaExhausted, self::NoEligibleTeam, self::ArtifactNotInstallable, self::DuplicateArtifact,
            self::VersionExists, self::EncryptedBinary, self::IdempotencyConflict, self::UploadCorrupt,
            self::DeviceLimitReached, self::DeviceOwnedElsewhere => 409,
            self::UploadIncomplete => 422,
            self::RateLimited => 429,
            self::AppleUnavailable, self::AppleNotConnected, self::ServiceUnavailable => 503,
            self::Internal => 500,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => 'Проверьте введённые данные.',
            self::Unauthenticated => 'Войдите в аккаунт, чтобы продолжить.',
            self::SessionExpired => 'Сеанс истёк. Войдите снова.',
            self::InvalidCredentials => 'Неверная эл. почта или пароль.',
            self::AccountSuspended => 'Аккаунт заблокирован. Обратитесь в поддержку.',
            self::TotpRequired => 'Подтвердите вход кодом из приложения-аутентификатора.',
            self::TotpInvalid => 'Код неверный или устарел.',
            self::Forbidden => 'Недостаточно прав для этого действия.',
            self::NotFound => 'Запрошенный объект не найден.',
            self::MethodNotAllowed => 'Метод запроса не поддерживается.',
            self::Conflict => 'Действие конфликтует с текущим состоянием.',
            self::IllegalStateTransition => 'Переход в это состояние сейчас невозможен.',
            self::RateLimited => 'Слишком много запросов. Повторите позже.',
            self::ActivationInvalid => 'Код активации недействителен.',
            self::ActivationAlreadyUsed => 'Этот код активации уже использован.',
            self::EnrollmentChallengeExpired => 'Срок действия профиля истёк. Скачайте его заново.',
            self::DeviceNotEligible => 'Устройство пока не готово к установке.',
            self::DeviceLimitReached => 'К аккаунту уже привязано максимальное число устройств.',
            self::DeviceOwnedElsewhere => 'Это устройство привязано к другому аккаунту. Обратитесь в поддержку.',
            self::EnrollmentPayloadInvalid => 'Не удалось прочитать данные устройства. Установите профиль заново.',
            self::AppleNotConnected => 'Регистрация устройств у Apple пока не подключена.',
            self::ClaimInvalid => 'Ссылка для открытия приложения недействительна или устарела.',
            self::DevicePendingApple => 'Регистрация устройства ещё обрабатывается.',
            self::QuotaExhausted => 'Регистрация новых устройств временно недоступна.',
            self::NoEligibleTeam => 'Регистрация новых устройств временно недоступна.',
            self::ArtifactNotInstallable => 'Это приложение сейчас недоступно для установки.',
            self::IncompatibleDevice => 'Приложение несовместимо с этим устройством.',
            self::InstallTokenExpired => 'Ссылка на установку устарела. Запросите новую.',
            self::DuplicateArtifact => 'Такой файл уже загружен.',
            self::UploadIncomplete => 'Загружены не все части файла.',
            self::UploadCorrupt => 'Размер или контрольная сумма файла не совпадает.',
            self::VersionExists => 'Эта версия приложения уже существует.',
            self::EncryptedBinary => 'Файл зашифрован и не может быть принят.',
            self::IdempotencyConflict => 'Повторный запрос с другими данными.',
            self::AppleUnavailable => 'Сервис Apple временно недоступен.',
            self::ServiceUnavailable => 'Сервис временно недоступен.',
            self::Internal => 'Внутренняя ошибка. Попробуйте позже.',
        };
    }
}
