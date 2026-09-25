<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Apple counts registered devices per product family per membership year
 * (FULL_PLAN §1.3); disabling a device does not free its slot until renewal.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
class MembershipYear extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    protected $fillable = ['apple_team_id', 'starts_at', 'ends_at', 'status'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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
