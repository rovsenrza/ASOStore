<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Services\Storefront\StorefrontStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontStatusController extends Controller
{
    public function __invoke(Request $request, StorefrontStatusResolver $resolver): JsonResponse
    {
        $user = $request->user('sanctum');

        return ApiResponse::ok($resolver->resolve($user instanceof User ? $user : null, EmailVerificationService::blocks($request)));
    }
}
