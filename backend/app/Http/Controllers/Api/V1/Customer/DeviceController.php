<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Services\Storefront\StorefrontStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * The caller's enrolled devices, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->devices()->with('latestRegistration')->latest('id')->get();

        return ApiResponse::ok($devices->map(fn (Device $device) => StorefrontStatusResolver::presentDevice($device))->all());
    }
}
