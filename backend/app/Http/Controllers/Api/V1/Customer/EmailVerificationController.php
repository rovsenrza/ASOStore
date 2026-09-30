<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        /** @var User $user */
        $user = $request->user();
        $this->verification->verify($user, $data['code']);

        return ApiResponse::ok((new MeResource($user->fresh()))->resolve($request));
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok(['resend_after' => $this->verification->send($user)], 202);
    }
}
