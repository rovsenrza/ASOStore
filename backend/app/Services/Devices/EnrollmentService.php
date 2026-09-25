<?php

namespace App\Services\Devices;

use App\Enums\DeviceFamily;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\RegisterDeviceJob;
use App\Models\Device;
use App\Models\EnrollmentChallenge;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Device enrollment through an iOS Profile Service (IMPLEMENTATION_PLAN §5.5).
 */
class EnrollmentService
{
    /**
     * Current UDIDs (XXXXXXXX-XXXXXXXXXXXXXXXX) and pre-2018 40-hex UDIDs.
     */
    private const UDID_PATTERN = '/^([0-9A-F]{8}-[0-9A-F]{16}|[0-9A-F]{40})$/';

    public function __construct(
        private readonly AuditService $audit,
        private readonly UdidHasher $hasher,
        private readonly EnrollmentProfileBuilder $profiles,
        private readonly EnrollmentPayloadParser $payloads,
        private readonly DeviceRegistrationService $registrations,
    ) {}

    /**
     * @return array{content: string, signed: bool}
     */
    public function issueProfile(User $user, string $callbackUrl, ?string $ip): array
    {
        if ($user->activeSubscription === null) {
            throw new ApiException(ErrorCode::Forbidden, 'Сначала активируйте код доступа.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        EnrollmentChallenge::create([
            'user_id' => $user->id,
            'challenge_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes((int) config('storefront.enrollment.challenge_minutes')),
            'ip' => $ip,
        ]);

        $xml = $this->profiles->build($callbackUrl.'?'.http_build_query(['challenge' => $token]), $token);
        $signed = $this->profiles->sign($xml);
        $this->audit->record('device.enrollment_profile_issued', $user, after: ['signed' => $signed !== null], actor: Actor::user($user));

        return ['content' => $signed ?? $xml, 'signed' => $signed !== null];
    }

    /**
     * Handles the device's answer. Throws ApiException with a stable code on
     * any problem; the controller turns it into a redirect the user can read.
     */
    public function complete(string $token, string $body): Device
    {
        $payload = $this->payloads->parse($body);

        if (! hash_equals($token, $payload['CHALLENGE'] ?? '')) {
            throw new ApiException(ErrorCode::EnrollmentPayloadInvalid, details: ['reason' => 'challenge']);
        }

        $udid = UdidHasher::normalize($payload['UDID'] ?? '');
        if (preg_match(self::UDID_PATTERN, $udid) !== 1) {
            throw new ApiException(ErrorCode::EnrollmentPayloadInvalid, details: ['reason' => 'udid']);
        }

        [$device, $registration] = DB::transaction(function () use ($token, $udid, $payload) {
            $challenge = EnrollmentChallenge::query()->where('challenge_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($challenge === null || $challenge->used_at !== null || $challenge->expires_at->isPast()) {
                throw new ApiException(ErrorCode::EnrollmentChallengeExpired);
            }

            $user = $challenge->user;
            if (! $user->isActive()) {
                throw new ApiException(ErrorCode::AccountSuspended);
            }

            $hash = $this->hasher->hash($udid);
            if (Device::query()->where('udid_hash', $hash)->where('user_id', '!=', $user->id)->exists()) {
                throw new ApiException(ErrorCode::DeviceOwnedElsewhere);
            }

            $device = Device::query()->where('user_id', $user->id)->where('udid_hash', $hash)->first();
            $isNew = $device === null;
            if ($isNew) {
                if ($user->devices()->count() >= (int) config('storefront.devices.max_per_account')) {
                    throw new ApiException(ErrorCode::DeviceLimitReached);
                }
                $device = new Device(['user_id' => $user->id]);
                $device->setUdid($udid);
            }

            $device->fill([
                'product' => mb_substr($payload['PRODUCT'] ?? '', 0, 32) ?: null,
                'os_version' => mb_substr($payload['VERSION'] ?? '', 0, 16) ?: null,
                'device_family' => DeviceFamily::fromProduct($payload['PRODUCT'] ?? null),
            ]);
            $device->enrolled_at = now();
            $device->save();

            $challenge->forceFill(['used_at' => now(), 'device_id' => $device->id])->save();
            $registration = $this->registrations->request($device);

            $this->audit->record($isNew ? 'device.enrolled' : 'device.reenrolled', $device, after: [
                'udid_hint' => $device->maskedUdid(),
                'product' => $device->product,
                'os_version' => $device->os_version,
                'family' => $device->device_family->value,
            ], actor: Actor::user($user));

            return [$device, $registration];
        });

        if ($registration !== null) {
            RegisterDeviceJob::dispatch($registration->id);
        }

        return $device;
    }
}
