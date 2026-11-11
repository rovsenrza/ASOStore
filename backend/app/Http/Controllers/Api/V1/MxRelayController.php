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
        $this->assertRelay($request);
        $data = $request->validate([
            'udid' => ['required', 'string', 'max:128'],
        ]);

        return response()->json($this->verdicts([$data['udid']], $hasher)[$data['udid']]);
    }

    /**
     * Every verdict the relay needs in one call. Asking one UDID at a time under its outbound
     * budget starved the checks: after a restart most users were never asked and stayed on the
     * fail-open default, so lapsed customers kept getting notifications for days.
     */
    public function entitlements(Request $request, UdidHasher $hasher): JsonResponse
    {
        $this->assertRelay($request);
        $data = $request->validate([
            'udids' => ['required', 'array', 'max:5000'],
            'udids.*' => ['required', 'string', 'max:128'],
        ]);

        return response()->json(['results' => $this->verdicts(array_values(array_unique($data['udids'])), $hasher)]);
    }

    private function assertRelay(Request $request): void
    {
        $secret = (string) config('storefront.mx_relay.secret', '');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-MX-Relay-Secret', ''))) {
            // Indistinguishable from a missing route to anything without the secret.
            throw new NotFoundHttpException;
        }
    }

    /**
     * @param  list<string>  $udids
     * @return array<string, array{active: bool, until: string|null, reason: string|null}>
     */
    private function verdicts(array $udids, UdidHasher $hasher): array
    {
        $hashes = [];
        foreach ($udids as $udid) {
            $hashes[$udid] = $hasher->hash($udid);
        }
        // The newest device row per UDID hash, as the single check always took.
        $devices = Device::query()
            ->whereIn('udid_hash', array_values(array_unique($hashes)))
            ->with(['user.activeSubscription'])
            ->orderBy('id')
            ->get()
            ->keyBy('udid_hash');

        $results = [];
        foreach ($hashes as $udid => $hash) {
            $device = $devices->get($hash);
            $user = $device?->user;
            $subscription = $user?->activeSubscription;
            $active = $user !== null && $user->isActive() && $subscription !== null;
            $results[$udid] = [
                'active' => $active,
                'until' => $active ? $subscription->ends_at?->toIso8601ZuluString() : null,
                'reason' => $active ? null : ($device === null ? 'UNKNOWN_DEVICE' : ($user === null ? 'NO_USER' : ($user->isActive() ? 'NO_SUBSCRIPTION' : 'ACCOUNT_BLOCKED'))),
            ];
        }

        return $results;
    }
}
