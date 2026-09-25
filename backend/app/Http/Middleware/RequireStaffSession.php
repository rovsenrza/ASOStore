<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API gate: a staff role, a browser session (never a bearer token), and
 * a TOTP check completed in that session (IMPLEMENTATION_PLAN D4).
 */
class RequireStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isStaff()) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        $verified = $request->hasSession()
            && $request->session()->get('admin.user_id') === $user->id
            && $request->session()->has('admin.totp_verified_at');

        if (! $verified) {
            throw new ApiException(ErrorCode::TotpRequired);
        }

        return $next($request);
    }
}
