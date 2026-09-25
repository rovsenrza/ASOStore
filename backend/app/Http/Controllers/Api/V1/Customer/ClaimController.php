<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Http\Responses\ApiResponse;
use App\Services\Storefront\ClaimService;
use App\Services\Storefront\StorefrontStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    public function __construct(private readonly ClaimService $claims) {}

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->claims->create($request->user()), 201);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        [$user, $device, $pair] = $this->claims->redeem($data['code'], $data['device_name'] ?? null, $request->ip());

        return ApiResponse::ok($pair->toArray() + [
            'user' => (new MeResource($user))->resolve($request),
            'device' => StorefrontStatusResolver::presentDevice($device->load('latestRegistration')),
        ], 201);
    }
}
