<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(ErrorCode::ServiceUnavailable, details: ['checks' => ['database' => 'failed']]);
        }

        return ApiResponse::ok([
            'status' => 'ok',
            'version' => config('storefront.api_version'),
            'time' => now()->toIso8601ZuluString(),
            'checks' => ['database' => 'ok'],
        ]);
    }
}
