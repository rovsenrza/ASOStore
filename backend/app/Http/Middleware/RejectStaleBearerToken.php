<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public catalog routes answer anonymous callers too, so an expired or revoked
 * access token would silently turn the native app into a guest (every app shown
 * as unavailable) instead of prompting it to refresh. A bearer token that does
 * not authenticate gets 401, which the app answers with /auth/refresh.
 */
class RejectStaleBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null && $request->user('sanctum') === null) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        return $next($request);
    }
}
