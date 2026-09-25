<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Services\Activation\ActivationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivationController extends Controller
{
    public function redeem(Request $request, ActivationCodeService $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);

        $subscription = $codes->redeem($request->user(), $data['code']);

        return ApiResponse::ok(['subscription' => (new SubscriptionResource($subscription))->resolve($request)], 201);
    }
}
