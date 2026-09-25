<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A proposal to register a blocked device with another eligible team.
 * PENDING until an admin approves or rejects it (FULL_PLAN §6.2, §12).
 *
 * @property string $status
 * @property string $selection_reason
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property int $device_id
 * @property int $apple_team_id
 * @property int $blocked_registration_id
 * @property int|null $registration_id
 */
class TeamAssignment extends Model
{
    use HasPublicId;

    protected $fillable = [
        'device_id', 'blocked_registration_id', 'apple_team_id', 'status', 'selection_reason',
        'decided_by', 'decision_reason', 'decided_at', 'registration_id',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }

    /**
     * @return BelongsTo<DeviceRegistration, $this>
     */
    public function blockedRegistration(): BelongsTo
    {
        return $this->belongsTo(DeviceRegistration::class, 'blocked_registration_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
