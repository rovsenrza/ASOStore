<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RESERVED while the Apple call is in flight, then CONSUMED or RELEASED (FULL_PLAN §6.2).
 *
 * @property string $status
 * @property Carbon $expires_at
 */
class QuotaReservation extends Model
{
    public const TTL_MINUTES = 30;

    protected $fillable = ['team_quota_id', 'device_registration_id', 'status', 'expires_at', 'released_reason'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<DeviceRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(DeviceRegistration::class, 'device_registration_id');
    }

    /**
     * @return BelongsTo<TeamQuota, $this>
     */
    public function quota(): BelongsTo
    {
        return $this->belongsTo(TeamQuota::class, 'team_quota_id');
    }
}
