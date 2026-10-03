<?php

namespace App\Services\Storefront;

use App\Enums\DeviceRegistrationStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\StorefrontClaim;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Auth\TokenPair;
use App\Services\Auth\TokenService;
use Illuminate\Support\Facades\DB;

/**
 * Binds the native app to an enrolled device (IMPLEMENTATION_PLAN G8). iOS
 * apps cannot read their UDID, but the portal knows the device from
 * enrollment: it issues a one-time code and opens storefront://claim?code=…,
 * and the app exchanges the code for device-bound tokens.
 */
class ClaimService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{code: string, url: string, expires_at: string}
     */
    public function create(User $user): array
    {
        $device = $user->latestDevice()->with('latestRegistration')->first();
        if ($device?->latestRegistration?->status !== DeviceRegistrationStatus::Eligible) {
            throw new ApiException(ErrorCode::DeviceNotEligible);
        }

        $code = $this->mint($user, $device);

        return [
            'code' => $code,
            'url' => config('storefront.claims.url_scheme').'://claim?'.http_build_query(['code' => $code]),
            'expires_at' => now()->addMinutes((int) config('storefront.claims.ttl_minutes'))->toIso8601ZuluString(),
        ];
    }

    /**
     * Mints a one-time, device-bound claim and returns the plaintext code (only the hash is stored).
     * Used for the code embedded in the storefront build, so first launch signs the customer in; it
     * gets a longer life than an interactive claim to cover download and install before first launch.
     */
    public function mint(User $user, Device $device, ?int $ttlMinutes = null): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        StorefrontClaim::create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes($ttlMinutes ?? (int) config('storefront.claims.ttl_minutes')),
        ]);

        return $code;
    }

    /**
     * @return array{0: User, 1: Device, 2: TokenPair}
     */
    public function redeem(#[\SensitiveParameter] string $code, ?string $deviceName, ?string $ip): array
    {
        return DB::transaction(function () use ($code, $deviceName, $ip) {
            $claim = StorefrontClaim::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if ($claim === null || $claim->redeemed_at !== null || $claim->expires_at->isPast()) {
                throw new ApiException(ErrorCode::ClaimInvalid);
            }

            $user = $claim->user;
            if (! $user->isActive()) {
                throw new ApiException(ErrorCode::AccountSuspended);
            }

            $device = $claim->device;
            $claim->forceFill(['redeemed_at' => now(), 'redeemed_ip' => $ip])->save();
            if ($device->storefront_claimed_at === null) {
                $device->forceFill(['storefront_claimed_at' => now()])->save();
            }

            $pair = $this->tokens->issue($user, $deviceName, $ip, device: $device);
            $this->audit->record('storefront.claimed', $device, after: ['device_name' => $deviceName], actor: Actor::user($user));

            return [$user, $device, $pair];
        });
    }
}
