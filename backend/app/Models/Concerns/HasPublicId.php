<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Internal BIGINT primary key plus a ULID public_id used in every URL and API
 * response (IMPLEMENTATION_PLAN D5).
 */
trait HasPublicId
{
    use HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
