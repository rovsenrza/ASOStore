<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Stored response for an Idempotency-Key replay (IMPLEMENTATION_PLAN §5.4).
 */
class IdempotencyKey extends Model
{
    use Prunable;

    protected $fillable = ['scope', 'route', 'key', 'request_hash', 'response_status', 'response_body', 'expires_at'];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
