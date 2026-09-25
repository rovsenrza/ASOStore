<?php

namespace App\Models;

use App\Enums\DeviceFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Device slots of one family for one team and membership year (FULL_PLAN §6.1).
 *
 * @property int $apple_team_id
 * @property int $membership_year_id
 * @property DeviceFamily $device_family
 * @property int $limit_count
 * @property int|null $apple_registered_count
 * @property Carbon|null $last_synced_at
 */
class TeamQuota extends Model
{
    protected $fillable = ['apple_team_id', 'membership_year_id', 'device_family', 'limit_count', 'apple_registered_count', 'last_synced_at'];

    protected function casts(): array
    {
        return ['device_family' => DeviceFamily::class, 'last_synced_at' => 'datetime'];
    }

    /**
     * Registrations that hold a slot: Apple counts a device for the whole
     * membership year, even when it is later disabled (FULL_PLAN §1.3).
     */
    public function registeredCount(): int
    {
        return DeviceRegistration::query()
            ->where('apple_team_id', $this->apple_team_id)
            ->where('membership_year_id', $this->membership_year_id)
            ->where('device_family', $this->device_family->value)
            ->whereIn('status', DeviceRegistration::CONSUMING_STATUSES)
            ->count();
    }

    /**
     * Reservations still in flight for registrations that do not yet hold a slot.
     */
    public function reservedCount(): int
    {
        return QuotaReservation::query()
            ->where('team_quota_id', $this->id)
            ->where('status', 'RESERVED')
            ->where('expires_at', '>', now())
            ->whereHas('registration', fn ($query) => $query->whereNotIn('status', DeviceRegistration::CONSUMING_STATUSES))
            ->count();
    }

    public function remaining(): int
    {
        return max(0, $this->limit_count - $this->registeredCount() - $this->reservedCount());
    }

    /**
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }

    /**
     * @return BelongsTo<MembershipYear, $this>
     */
    public function membershipYear(): BelongsTo
    {
        return $this->belongsTo(MembershipYear::class);
    }
}
