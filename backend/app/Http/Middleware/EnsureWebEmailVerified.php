<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Auth\EmailVerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website sessions need a confirmed email before activation, devices and
 * installs. Token (native app) requests pass through unchanged.
 */
class EnsureWebEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (EmailVerificationService::blocks($request)) {
            throw new ApiException(ErrorCode::EmailNotVerified);
        }

        return $next($request);
    }
}
