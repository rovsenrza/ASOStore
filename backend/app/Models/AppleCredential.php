<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App Store Connect API key metadata. The private key itself lives in the
 * secret store named by vault_reference (IMPLEMENTATION_PLAN D8).
 *
 * @property Carbon|null $last_verified_at
 */
class AppleCredential extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    protected $fillable = ['apple_team_id', 'issuer_id', 'key_id', 'vault_reference', 'status'];

    protected $hidden = ['vault_reference'];

    protected function casts(): array
    {
        return [
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }
}
