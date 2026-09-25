<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Which enrolled device a request speaks for. Native app tokens are bound to
 * a device by the storefront claim (IMPLEMENTATION_PLAN G8); other tokens and
 * browser sessions (the portal in Safari on the iPhone) speak for the
 * account's latest enrolled device.
 */
class CurrentDevice
{
    public function resolve(Request $request): ?Device
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey() !== null) {
            $bound = RefreshToken::query()
                ->where('access_token_id', $token->getKey())
                ->whereNotNull('device_id')
                ->first()
                ?->device;
            if ($bound !== null) {
                return $bound;
            }
        }

        // Unbound tokens and browser sessions: the account's enrolled device
        // (one per account by default, STOREFRONT_MAX_DEVICES_PER_ACCOUNT).
        return $user->latestDevice()->first();
    }
}
