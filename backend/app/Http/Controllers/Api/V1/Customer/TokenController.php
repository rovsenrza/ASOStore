<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ActorType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Http\Responses\ApiResponse;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Auth\CredentialVerifier;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Bearer tokens for the native app (IMPLEMENTATION_PLAN D3).
 */
class TokenController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditService $audit,
        private readonly CredentialVerifier $credentials,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $email = Str::lower(trim($data['email']));

        $user = $this->credentials->verify($email, $data['password']);
        if ($user === null) {
            $this->audit->record('auth.login_failed', actor: new Actor(ActorType::Anonymous, null, $email));

            throw new ApiException(ErrorCode::InvalidCredentials);
        }

        if (! $user->isActive()) {
            throw new ApiException(ErrorCode::AccountSuspended);
        }

        $pair = $this->tokens->issue($user, $data['device_name'] ?? null, $request->ip());
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.token_issued', $user, after: ['device_name' => $data['device_name'] ?? null], actor: Actor::user($user));

        return ApiResponse::ok($pair->toArray() + ['user' => (new MeResource($user))->resolve($request)], 201);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:200']]);

        [$user, $pair] = $this->tokens->refresh($data['refresh_token'], $request->ip());

        return ApiResponse::ok($pair->toArray() + ['user' => (new MeResource($user))->resolve($request)]);
    }
}
