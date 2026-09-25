<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Runner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the signing runner (IMPLEMENTATION_PLAN D9). Each request is
 * signed with the runner's key:
 *
 *   X-Runner-Key, X-Timestamp (unix), X-Nonce, X-Content-SHA256 (hex of the body),
 *   X-Signature = hex HMAC-SHA256(secret, METHOD\nPATH\nTIMESTAMP\nNONCE\nCONTENT_SHA256)
 *
 * Timestamps older than five minutes and reused nonces are rejected. Large
 * uploads are streamed: their body hash is checked by the controller.
 */
class VerifyWorkerSignature
{
    public const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->header('X-Runner-Key');
        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $contentHash = strtolower((string) $request->header('X-Content-SHA256'));
        $signature = strtolower((string) $request->header('X-Signature'));

        $runner = $keyId === '' ? null : Runner::query()->where('key_id', $keyId)->first();
        $valid = $runner !== null
            && $runner->status === 'ACTIVE'
            && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= self::MAX_SKEW_SECONDS
            && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) === 1
            && preg_match('/^[a-f0-9]{64}$/', $contentHash) === 1
            && hash_equals(self::sign($runner->secret_encrypted, $request->method(), $request->getPathInfo(), $timestamp, $nonce, $contentHash), $signature);

        if (! $valid || ! Cache::add("worker-nonce:{$keyId}:{$nonce}", 1, self::MAX_SKEW_SECONDS * 2)) {
            Log::warning('worker.signature_rejected', ['runner' => $keyId, 'path' => $request->getPathInfo(), 'ip' => $request->ip()]);

            throw new ApiException(ErrorCode::Unauthenticated, 'Invalid runner signature.');
        }

        if (! self::isStreamedUpload($request)
            && ! hash_equals($contentHash, hash('sha256', $request->getContent()))) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Body does not match the signed hash.');
        }

        $request->attributes->set('runner', $runner);

        return $next($request);
    }

    public static function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $contentHash): string
    {
        return hash_hmac('sha256', strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$contentHash, $secret);
    }

    private static function isStreamedUpload(Request $request): bool
    {
        return $request->isMethod('PUT') && str_ends_with($request->getPathInfo(), '/artifact');
    }
}
