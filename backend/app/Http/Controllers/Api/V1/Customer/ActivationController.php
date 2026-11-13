<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Services\Activation\ActivationCodeService;
use App\Services\Devices\CustomerDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    /**
     * Puts a code on one of the caller's iPhones: device_id to extend it, new_device for a new
     * iPhone (it becomes a pending device until that iPhone installs the profile). Without
     * either, the only device takes it; with several, DEVICE_TARGET_REQUIRED asks which.
     */
    public function redeem(Request $request, ActivationCodeService $codes, CustomerDevices $devices): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'device_id' => ['nullable', 'string', 'size:26'],
            'new_device' => ['nullable', 'boolean'],
        ]);
        $device = filled($data['device_id'] ?? null)
            ? Device::query()->where('user_id', $request->user()->id)->where('public_id', strtolower($data['device_id']))->firstOrFail()
            : null;

        $subscription = $codes->redeem($request->user(), $data['code'], $device, (bool) ($data['new_device'] ?? false));

        return ApiResponse::ok([
            'subscription' => (new SubscriptionResource($subscription))->resolve($request),
            'device' => $devices->present($subscription->device()->firstOrFail()),
        ], 201);
    }
}
