<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honours the Idempotency-Key header (IMPLEMENTATION_PLAN §5.4): the first
 * response for a key is stored for 24 hours and replayed for retries with the
 * same body; a different body under the same key is rejected.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null) {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['fields' => [self::HEADER => ['Invalid idempotency key.']]]);
        }

        $scope = $request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip();
        $route = $request->route()?->getName() ?? $request->path();
        $requestHash = hash('sha256', $request->method().'|'.$request->getContent());

        $existing = IdempotencyKey::query()
            ->where(['scope' => $scope, 'route' => $route, 'key' => $key])
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $record = IdempotencyKey::create([
                'scope' => $scope,
                'route' => $route,
                'key' => $key,
                'request_hash' => $requestHash,
                'expires_at' => now()->addDay(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // An expired row with the same key, or a concurrent first request.
            IdempotencyKey::query()->where(['scope' => $scope, 'route' => $route, 'key' => $key])->where('expires_at', '<=', now())->delete();
            throw new ApiException(ErrorCode::Conflict, 'Запрос уже обрабатывается. Повторите позже.');
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            $record->delete();
        } else {
            $record->update(['response_status' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        }

        return $response;
    }

    private function replay(IdempotencyKey $existing, string $requestHash): Response
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new ApiException(ErrorCode::IdempotencyConflict);
        }

        if ($existing->response_status === null) {
            throw new ApiException(ErrorCode::Conflict, 'Запрос уже обрабатывается. Повторите позже.');
        }

        return response((string) $existing->response_body, $existing->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
