<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts API requests, 5xx answers and total duration per minute, for the
 * api_request_duration and api_error_rate metrics (FULL_PLAN §14). Cache
 * increments only; the scheduler turns them into metric_snapshots.
 */
class RecordRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        try {
            $bucket = 'req-metrics:'.now()->format('YmdHi');
            Cache::add($bucket.':count', 0, 7200);
            Cache::add($bucket.':errors', 0, 7200);
            Cache::add($bucket.':ms', 0, 7200);
            Cache::increment($bucket.':count');
            Cache::increment($bucket.':ms', (int) round((microtime(true) - $started) * 1000));
            if ($response->getStatusCode() >= 500) {
                Cache::increment($bucket.':errors');
            }
        } catch (\Throwable) {
            // Metrics must never break a request.
        }

        return $response;
    }
}
