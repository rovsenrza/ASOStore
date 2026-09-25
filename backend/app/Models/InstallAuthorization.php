<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Single-use, device-bound install token behind the manifest URL (IMPLEMENTATION_PLAN §5.6).
 * Only the token hash is stored.
 *
 * @property int $installation_id
 * @property Carbon $expires_at
 * @property Carbon|null $manifest_fetched_at
 */
class InstallAuthorization extends Model
{
    protected $fillable = ['installation_id', 'token_hash', 'expires_at', 'manifest_fetched_at', 'ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'manifest_fetched_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Installation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }
}
