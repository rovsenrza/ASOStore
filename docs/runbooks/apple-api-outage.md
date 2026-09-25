# Apple API outage

**Symptoms.** Device registrations stay `APPLE_PENDING` with reason `APPLE_PROCESSING` or an Apple
error; `apple_api_429_count` rises on the dashboard; profile provisioning jobs (`PrepareSigningJob`)
are postponed; team verification answers `APPLE_UNAVAILABLE`.

**What the system already does.** Rate limits (429) and 5xx answers are retried with Apple's
`Retry-After` or backoff; the attempt is postponed, not failed (`RetryLater`). Nothing switches
teams and nothing is lost: jobs stay queued and registrations stay pending.

1. Check <https://developer.apple.com/system-status/> for App Store Connect API incidents.
2. Admin → Задачи: filter `FAILED_RETRYABLE` / `QUEUED`. Look at `error_message` of the latest attempts.
   - `401/403 rejected the API key` → not an outage: follow [credential rotation](credential-rotation.md).
   - `rate limit` → wait; do not retry manually (that only adds requests).
3. Tell support: customers see «Устройство проходит проверку» and no promised time — that copy is correct.
4. When Apple recovers, the five-minute `SyncDeviceRegistrationsJob` and the queue drain on their own.
   Jobs that ended `FAILED_PERMANENT` during the outage: Admin → Задачи → Повторить (reason: "Apple outage <date>").
5. Afterwards run Admin → Команды Apple → «Сверить с Apple» for each team and check for mismatches.
