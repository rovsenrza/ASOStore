<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 codes for staff sign-in (IMPLEMENTATION_PLAN D4). An accepted code
 * cannot be accepted again: the matching time step is remembered per user.
 */
class TotpService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function provisioningUri(User $user, #[\SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('storefront.brand').' Admin', $user->email, $secret);
    }

    public function qrSvg(string $uri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($uri);
    }

    /**
     * Checks the code against the user's stored secret, allowing one step of
     * clock drift, and records the step on success.
     */
    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        if ($user->totp_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $step = $this->google2fa->verifyKeyNewer($user->totp_secret, $code, $user->totp_last_step ?? 0, 1);

        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['totp_last_step' => $step])->save();

        return true;
    }

    /**
     * Current code for a secret. Used by tests and local tooling only.
     */
    public function currentCode(#[\SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
