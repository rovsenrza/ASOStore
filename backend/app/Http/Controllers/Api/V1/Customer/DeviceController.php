<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Devices\CurrentDevice;
use App\Services\Devices\CustomerDevices;
use App\Services\Devices\DeviceSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * «Мои устройства»: every iPhone on the account with its own access, which one is asking
     * (current), and how many more the account may add.
     */
    public function index(Request $request, CustomerDevices $devices, CurrentDevice $current): JsonResponse
    {
        $list = $devices->list($request->user());

        return ApiResponse::ok($list, meta: [
            'current' => $current->resolve($request)?->public_id,
            'limit' => DeviceSlots::limit(),
            'can_add' => count($list) < DeviceSlots::limit(),
        ]);
    }
}
