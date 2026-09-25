<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Auth\CredentialVerifier;
use App\Services\Auth\TotpService;
use App\Support\Abilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Two-step staff sign-in (IMPLEMENTATION_PLAN D4): password, then a TOTP code.
 * The session is only authenticated after the second step; the first step
 * enrols an authenticator app if the account has none yet.
 */
class AdminAuthController extends Controller
{
    private const PENDING_USER = 'admin.pending_user_id';

    private const PENDING_UNTIL = 'admin.pending_until';

    public function __construct(
        private readonly AuditService $audit,
        private readonly TotpService $totp,
        private readonly CredentialVerifier $credentials,
    ) {}

    public function login(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $email = Str::lower(trim($data['email']));

        $user = $this->credentials->verify($email, $data['password']);
        if ($user === null) {
            $this->audit->record('admin.login_failed', actor: new Actor(ActorType::Anonymous, null, $email));

            throw new ApiException(ErrorCode::InvalidCredentials);
        }

        if (! $user->isActive()) {
            throw new ApiException(ErrorCode::AccountSuspended);
        }
        if (! $user->isStaff()) {
            $this->audit->record('admin.login_denied', $user, reason: 'not staff', actor: Actor::user($user));

            throw new ApiException(ErrorCode::Forbidden);
        }

        // Start from a clean, unauthenticated session for the second step. A new
        // CSRF token must follow the invalidation or the browser's next request fails with 419.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(self::PENDING_USER, $user->id);
        $request->session()->put(self::PENDING_UNTIL, now()->addMinutes((int) config('storefront.auth.admin_totp_pending_minutes'))->getTimestamp());

        if ($user->hasConfirmedTotp()) {
            return ApiResponse::ok(['next_step' => 'totp', 'enrollment' => null]);
        }

        if ($user->totp_secret === null) {
            $user->forceFill(['totp_secret' => $this->totp->generateSecret(), 'totp_last_step' => null])->save();
        }
        $uri = $this->totp->provisioningUri($user, $user->totp_secret);

        return ApiResponse::ok([
            'next_step' => 'totp_enrollment',
            'enrollment' => [
                'secret' => $user->totp_secret,
                'otpauth_uri' => $uri,
                'qr_svg' => $this->totp->qrSvg($uri),
            ],
        ]);
    }

    public function totp(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $userId = $request->hasSession() ? $request->session()->get(self::PENDING_USER) : null;
        $until = $request->hasSession() ? (int) $request->session()->get(self::PENDING_UNTIL) : 0;
        if ($userId === null || $until < now()->getTimestamp()) {
            throw new ApiException(ErrorCode::TotpRequired, 'Войдите заново: срок подтверждения истёк.');
        }

        $user = User::query()->findOrFail($userId);

        if (! $this->totp->verify($user, preg_replace('/\s+/', '', $data['code']) ?? '')) {
            $this->audit->record('admin.totp_failed', $user, actor: Actor::user($user));

            throw new ApiException(ErrorCode::TotpInvalid);
        }

        if (! $user->hasConfirmedTotp()) {
            $user->forceFill(['totp_confirmed_at' => now()])->save();
            $this->audit->record('admin.totp_enrolled', $user, actor: Actor::user($user));
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget([self::PENDING_USER, self::PENDING_UNTIL]);
        $request->session()->put('admin.user_id', $user->id);
        $request->session()->put('admin.totp_verified_at', now()->getTimestamp());

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('admin.login', $user, actor: Actor::user($user));

        return ApiResponse::ok($this->presentMe($user));
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->presentMe($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user instanceof User) {
            $this->audit->record('admin.logout', $user, actor: Actor::user($user));
        }

        return ApiResponse::ok(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMe(User $user): array
    {
        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roleSlugs(),
            'permissions' => Abilities::grantedTo($user),
        ];
    }
}
