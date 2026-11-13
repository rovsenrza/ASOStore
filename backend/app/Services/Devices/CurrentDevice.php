<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Which enrolled device a request speaks for. Native app tokens are bound to
 * a device by the storefront claim (IMPLEMENTATION_PLAN G8). The website names
 * the iPhone it runs on in the X-Storefront-Device header (remembered when that
 * iPhone enrolled, or picked in «Мои устройства»); otherwise the account's
 * latest enrolled device.
 */
class CurrentDevice
{
    public function resolve(Request $request): ?Device
    {
        // Public catalog routes have no auth middleware, so the default guard does not
        // see the native app's bearer token; ask Sanctum, as the catalog resources do.
        $user = $request->user('sanctum') ?? $request->user();
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

        $named = trim((string) $request->header('X-Storefront-Device', ''));
        if ($named !== '' && preg_match('/^[0-9a-z]{26}$/i', $named) === 1) {
            $device = $user->devices()->enrolled()->where('public_id', strtolower($named))->first();
            if ($device !== null) {
                return $device;
            }
        }

        return $user->latestDevice()->first();
    }
}
