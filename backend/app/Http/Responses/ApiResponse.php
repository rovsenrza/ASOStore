<?php

namespace App\Http\Responses;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use stdClass;

/**
 * The {data, meta, error} envelope every API response uses (FULL_PLAN §9).
 */
final class ApiResponse
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => self::meta($meta),
            'error' => null,
        ], $status, [], self::JSON_FLAGS);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<mixed>  $items
     */
    public static function paginated(LengthAwarePaginator $paginator, array $items): JsonResponse
    {
        return self::ok($items, meta: [
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        ErrorCode $code,
        ?string $message = null,
        array $details = [],
        ?int $status = null,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'data' => null,
            'meta' => self::meta(),
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->message(),
                'details' => $details === [] ? new stdClass : $details,
            ],
        ], $status ?? $code->httpStatus(), $headers, self::JSON_FLAGS);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra = []): array
    {
        return ['request_id' => Context::get('request_id')] + $extra;
    }
}
