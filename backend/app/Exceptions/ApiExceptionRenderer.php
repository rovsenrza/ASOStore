<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every exception under /api/* as the standard envelope with a stable
 * error code (IMPLEMENTATION_PLAN §5.2). Internal details never leak unless
 * APP_DEBUG is on.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? self::toResponse($e) : null;
        });
    }

    public static function toResponse(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->details, $e->httpStatus()),
            $e instanceof ValidationException => ApiResponse::error(ErrorCode::ValidationFailed, details: ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => ApiResponse::error(ErrorCode::Unauthenticated),
            $e instanceof AccessDeniedHttpException => ApiResponse::error(ErrorCode::Forbidden),
            $e instanceof NotFoundHttpException => ApiResponse::error(ErrorCode::NotFound),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(ErrorCode::MethodNotAllowed, headers: $e->getHeaders()),
            $e instanceof TooManyRequestsHttpException => ApiResponse::error(ErrorCode::RateLimited, headers: $e->getHeaders()),
            // 419: the CSRF token no longer matches the session.
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ApiResponse::error(ErrorCode::SessionExpired, status: 419),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => ApiResponse::error(ErrorCode::ServiceUnavailable),
            default => ApiResponse::error(ErrorCode::Internal, details: config('app.debug')
                ? ['exception' => $e::class, 'message' => $e->getMessage()]
                : []),
        };
    }
}
