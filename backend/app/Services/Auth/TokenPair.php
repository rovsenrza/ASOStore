<?php

namespace App\Services\Auth;

use Carbon\CarbonInterface;

/**
 * Access + refresh tokens handed to the native app (IMPLEMENTATION_PLAN D3).
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $accessTokenExpiresAt,
        public string $refreshToken,
        public CarbonInterface $refreshTokenExpiresAt,
        public int $refreshTokenId,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt->toIso8601ZuluString(),
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt->toIso8601ZuluString(),
        ];
    }
}
