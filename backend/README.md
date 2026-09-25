# Backend

Laravel 12 on PHP 8.2+ and MySQL 8. Serves the customer API at `/api/v1` and, after `scripts/build-public.sh`, the static portal and admin panel.

Setup and commands: see the [root README](../README.md).

## Conventions

- **Envelope.** Every API response is `{data, meta: {request_id}, error}` ([ApiResponse](app/Http/Responses/ApiResponse.php)). Exceptions under `/api/*` are rendered with stable codes from [ErrorCode](app/Enums/ErrorCode.php) by [ApiExceptionRenderer](app/Exceptions/ApiExceptionRenderer.php). Add a code in both `ErrorCode` and `docs/api/openapi.yaml`; a contract test fails if they differ.
- **Request IDs.** [AssignRequestId](app/Http/Middleware/AssignRequestId.php) accepts or generates `X-Request-Id` and shares it through Laravel `Context` with logs, audit events and queued jobs.
- **Public IDs.** Tables keep BIGINT keys; APIs expose ULID `public_id` only ([HasPublicId](app/Models/Concerns/HasPublicId.php)).
- **State machines.** Status enums declare their legal moves ([app/Enums](app/Enums)). Change status only through [StateMachine::transition()](app/StateMachines/StateMachine.php), which locks the row and writes the audit event in the same transaction.
- **Audit.** Write through [AuditService](app/Services/Audit/AuditService.php); sensitive keys are redacted. MySQL triggers make `audit_logs` append-only and freeze the file identity of `app_artifacts`.
- **UDIDs.** Encrypted at rest with an HMAC blind index ([Device](app/Models/Device.php), [UdidHasher](app/Services/Devices/UdidHasher.php)); never serialized or logged.
- **Authentication.** Browsers use Sanctum session cookies with CSRF (`statefulApi`); the native app uses 15-minute bearer tokens plus rotating refresh tokens ([TokenService](app/Services/Auth/TokenService.php)). Reusing a spent refresh token revokes its whole family. After `session()->invalidate()`, always call `regenerateToken()`, or the browser's next request fails with 419.
- **Admin access.** `/api/v1/admin/*` needs a staff role, a browser session and a completed TOTP step ([RequireStaffSession](app/Http/Middleware/RequireStaffSession.php)); bearer tokens never work there. Permissions per role live in [Abilities](app/Support/Abilities.php) and are checked with `->can()` on routes.
- **Idempotency.** Routes with the `idempotent` middleware replay the stored response for a repeated `Idempotency-Key` ([Idempotent](app/Http/Middleware/Idempotent.php)).
- **Model defaults.** Columns with database defaults must also be listed in the model's `$attributes`, so new instances are complete before a reload (strict mode throws otherwise).
- **Apple.** Everything Apple goes through [AppleIntegration](app/Services/Apple/AppleIntegration.php) with three drivers (`disabled`, `fake`, `appstoreconnect`) chosen by `STOREFRONT_APPLE_DRIVER`. Apple API keys live in the secret store ([SecretStore](app/Services/Apple/SecretStore.php)); the database holds only a reference. The fake driver refuses to run in production.
- **Device registration.** Enrollment ([EnrollmentService](app/Services/Devices/EnrollmentService.php)) verifies the CMS signature of the device answer and a single-use challenge. Registration ([DeviceRegistrationService](app/Services/Devices/DeviceRegistrationService.php)) reserves a device slot under a row lock on the team; at the per-family limit it blocks and never tries another team. It runs in `RegisterDeviceJob`, so local development needs a queue worker (`scripts/dev.sh`).
- **Install state.** The CTA for each app comes from [InstallStateResolver](app/Services/Catalog/InstallStateResolver.php). Clients never infer installability.

## Tests

Pest runs against MySQL (`storefront_test`) because triggers and row locks are part of the behaviour under test. `tests/Feature/Contract` validates live responses and the shared examples against `docs/api/openapi.yaml`.
