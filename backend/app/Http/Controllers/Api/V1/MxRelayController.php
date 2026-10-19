<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\UdidHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Internal endpoint for the MAX notification relay (ios/max-relay + /opt/mxrelay). The relay
 * runs one server-side session mirror per MAX user and may only mirror / push notifications while
 * that user's storefront subscription is valid. A re-signed MAX build is per-device, so its
 * provisioning profile names exactly one device UDID; the injected hook reads that UDID and the
 * relay asks here whether the owning account still has an active subscription.
 *
 * Not customer-facing: guarded by the shared secret storefront.mx_relay.secret (sent in the
 * X-MX-Relay-Secret header). With no secret configured the route 404s, so it is never open.
 */
class MxRelayController extends Controller
{
    public function entitlement(Request $request, UdidHasher $hasher): JsonResponse
    {
        $secret = (string) config('storefront.mx_relay.secret', '');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-MX-Relay-Secret', ''))) {
            // Indistinguishable from a missing route to anything without the secret.
            throw new NotFoundHttpException;
        }

        $data = $request->validate([
            'udid' => ['required', 'string', 'max:128'],
        ]);

        $device = Device::query()
            ->where('udid_hash', $hasher->hash($data['udid']))
            ->with(['user.activeSubscription'])
            ->latest('id')
            ->first();

        $user = $device?->user;
        $subscription = $user?->activeSubscription;
        $active = $user !== null && $user->isActive() && $subscription !== null;

        return response()->json([
            'active' => $active,
            'until' => $active ? $subscription->ends_at?->toIso8601ZuluString() : null,
            'reason' => $active ? null : ($device === null ? 'UNKNOWN_DEVICE' : ($user === null ? 'NO_USER' : ($user->isActive() ? 'NO_SUBSCRIPTION' : 'ACCOUNT_BLOCKED'))),
        ]);
    }
}
