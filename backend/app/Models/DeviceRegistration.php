<?php

namespace App\Models;

use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One device registered (or being registered) with one Apple team for one
 * membership year. Status changes go through StateMachine.
 *
 * @property DeviceRegistrationStatus $status
 * @property DeviceFamily $device_family
 * @property Carbon|null $registered_at
 * @property Carbon|null $eligible_at
 * @property Carbon|null $last_synced_at
 */
class DeviceRegistration extends Model
{
    use HasPublicId;

    /**
     * Statuses that occupy one of the team's device slots for the year.
     */
    public const CONSUMING_STATUSES = ['APPLE_PENDING', 'ELIGIBLE', 'DISABLED'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ENROLLED',
        'attempts' => 0,
    ];

    protected $fillable = ['device_id', 'apple_team_id', 'membership_year_id', 'udid_hash', 'device_family', 'status', 'status_reason'];

    protected $hidden = ['id', 'udid_hash'];

    protected function casts(): array
    {
        return [
            'status' => DeviceRegistrationStatus::class,
            'device_family' => DeviceFamily::class,
            'attempts' => 'integer',
            'registered_at' => 'datetime',
            'eligible_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
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
     * @return BelongsTo<MembershipYear, $this>
     */
    public function membershipYear(): BelongsTo
    {
        return $this->belongsTo(MembershipYear::class);
    }
}
