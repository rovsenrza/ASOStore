<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a well-formed X-Request-Id from the client or generates one, shares
 * it with logs, audit events and queued jobs, and returns it on the response.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $incoming) === 1
            ? $incoming
            : strtolower((string) Str::ulid());

        $request->attributes->set('request_id', $requestId);
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
