<?php

namespace App\Services\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Short-lived Sanctum access tokens plus rotating refresh tokens for the
 * native app (IMPLEMENTATION_PLAN D3, G10).
 *
 * Every refresh spends the presented token and issues a new pair in the same
 * family. Presenting a spent token means it leaked or was replayed, so the
 * whole family is revoked and the user must sign in again.
 */
class TokenService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  Device|null  $device  The enrolled device these tokens are bound to (storefront claim).
     */
    public function issue(User $user, ?string $deviceName, ?string $ip, ?string $familyId = null, ?Device $device = null): TokenPair
    {
        $accessExpiresAt = now()->addMinutes((int) config('storefront.auth.access_token_minutes'));
        $access = $user->createToken($deviceName ?: 'ios', ['*'], $accessExpiresAt);

        $plainRefresh = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $refresh = RefreshToken::create([
            'user_id' => $user->id,
            'device_id' => $device?->id,
            'family_id' => $familyId ?? strtolower((string) Str::ulid()),
            'token_hash' => hash('sha256', $plainRefresh),
            'access_token_id' => $access->accessToken->getKey(),
            'device_name' => $deviceName,
            'created_ip' => $ip,
            'expires_at' => now()->addDays((int) config('storefront.auth.refresh_token_days')),
        ]);

        return new TokenPair($access->plainTextToken, $accessExpiresAt, $plainRefresh, $refresh->expires_at, $refresh->id);
    }

    /**
     * @return array{0: User, 1: TokenPair}
     */
    public function refresh(#[\SensitiveParameter] string $plainRefresh, ?string $ip): array
    {
        // Decide inside the transaction, throw after it commits so that a
        // family revocation is never rolled back by the error it raises.
        $outcome = DB::transaction(function () use ($plainRefresh, $ip) {
            $token = RefreshToken::query()
                ->where('token_hash', hash('sha256', $plainRefresh))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return ErrorCode::Unauthenticated;
            }

            $user = $token->user;

            if ($token->isSpent()) {
                $this->revokeFamily($token->family_id);
                $this->audit->record('auth.refresh_reuse_detected', $user, after: ['family_id' => $token->family_id], actor: Actor::user($user));

                return ErrorCode::SessionExpired;
            }

            if ($token->isExpired()) {
                return ErrorCode::SessionExpired;
            }

            if (! $user->isActive()) {
                $this->revokeFamily($token->family_id);

                return ErrorCode::AccountSuspended;
            }

            $token->forceFill(['used_at' => now()])->save();
            $token->accessToken?->delete();

            $pair = $this->issue($user, $token->device_name, $ip, $token->family_id, $token->device);
            $token->forceFill(['replaced_by_id' => $pair->refreshTokenId])->save();

            return [$user, $pair];
        });

        if ($outcome instanceof ErrorCode) {
            throw new ApiException($outcome);
        }

        return $outcome;
    }

    /**
     * Sign-out of one native session: its access token and every refresh token of its family.
     */
    public function revokeForAccessToken(PersonalAccessToken $accessToken): void
    {
        $familyId = RefreshToken::query()->where('access_token_id', $accessToken->getKey())->value('family_id');

        if ($familyId !== null) {
            $this->revokeFamily($familyId);
        }

        $accessToken->delete();
    }

    public function revokeFamily(string $familyId): void
    {
        $accessTokenIds = RefreshToken::query()->where('family_id', $familyId)->whereNotNull('access_token_id')->pluck('access_token_id');

        PersonalAccessToken::query()->whereIn('id', $accessTokenIds)->delete();
        RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * Ends every native and browser session of a user (password reset, suspension).
     */
    public function revokeAllForUser(User $user): void
    {
        $user->tokens()->delete();
        RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
