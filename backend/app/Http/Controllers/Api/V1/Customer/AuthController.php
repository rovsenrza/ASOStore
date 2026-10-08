<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ErrorCode;
use App\Enums\RoleSlug;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\TokenService;
use App\Services\Devices\CurrentDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Browser sign-in for the portal (Sanctum session cookies, IMPLEMENTATION_PLAN D2).
 * The native app uses TokenController instead.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TokenService $tokens,
        private readonly EmailVerificationService $verification,
        private readonly CurrentDevice $currentDevice,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $this->requireSession($request);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:200', self::passwordRule()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create($data);
            $user->roles()->attach(Role::query()->where('slug', RoleSlug::Customer->value)->firstOrFail());
            $this->audit->record('auth.registered', $user, actor: Actor::user($user));

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        try {
            $this->verification->send($user);
        } catch (Throwable $exception) {
            // The account exists; the customer can ask for the code again.
            report($exception);
        }

        return ApiResponse::ok((new MeResource($user->fresh()))->resolve($request), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->requireSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));

        $guard = Auth::guard('web');
        if (! $guard->attempt($credentials)) {
            $this->audit->record('auth.login_failed', actor: Actor::anonymous($credentials['email']));

            throw new ApiException(ErrorCode::InvalidCredentials);
        }

        /** @var User $user */
        $user = $guard->user();
        if (! $user->isActive()) {
            $guard->logout();

            throw new ApiException(ErrorCode::AccountSuspended);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, actor: Actor::user($user));

        return ApiResponse::ok((new MeResource($user))->resolve($request));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revokeForAccessToken($token);
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->audit->record('auth.logout', $user, actor: Actor::user($user));

        return ApiResponse::ok(null);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok((new MeResource($request->user(), $this->resolveAppUpdate($request)))->resolve($request));
    }

    /**
     * A newer storefront build than the one the app sent in `X-App-Build`, scoped to this
     * device's enrolled Apple team — each team publishes its own storefront variant on its own
     * schedule (ADR 0001), so a team's device must only ever be told about ITS team's build.
     *
     * @return array{version: ?string, build_number: int}|null
     */
    private function resolveAppUpdate(Request $request): ?array
    {
        $clientBuild = (int) $request->header('X-App-Build');
        if ($clientBuild <= 0) {
            return null;
        }

        $device = $this->currentDevice->resolve($request);
        $artifact = $device?->latestRegistration?->team?->storefrontApp?->publishedArtifact;
        $latestBuild = (int) ($artifact?->build_number ?? 0);
        if ($latestBuild <= $clientBuild) {
            return null;
        }

        return ['version' => $artifact?->version, 'build_number' => $latestBuild];
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));

        Password::broker()->sendResetLink(['email' => $email]);
        $this->audit->record('auth.password_reset_requested', actor: Actor::anonymous($email));

        // Same answer whether or not the account exists.
        return ApiResponse::ok(['accepted' => true], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200', self::passwordRule()],
        ]);
        $data['email'] = Str::lower(trim($data['email']));

        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            // The reset link reached this address, so it is confirmed.
            $this->verification->markVerified($user);
            $this->tokens->revokeAllForUser($user);
            $this->audit->record('auth.password_reset', $user, actor: Actor::user($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Ссылка для сброса недействительна или устарела.', [
                'fields' => ['token' => ['Ссылка для сброса недействительна или устарела.']],
            ]);
        }

        return ApiResponse::ok(['reset' => true]);
    }

    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min(10)->letters()->numbers();
    }

    /**
     * Session sign-in only works for same-origin browser requests (Sanctum stateful domains).
     */
    private function requireSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiException(ErrorCode::Forbidden, 'Для приложения используйте /auth/tokens.');
        }
    }
}
