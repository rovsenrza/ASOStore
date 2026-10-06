<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ad hoc provisioning profile for one (team, bundle ID, device)
 * (IMPLEMENTATION_PLAN D10), or a shared one listing every eligible device of the
 * team at the time (device_id null, `cohort` names that device set). Profiles are
 * recreated, never edited; a shared profile for an older cohort stays valid for the
 * builds that embed it.
 *
 * @property int $apple_team_id
 * @property int $certificate_id
 * @property int|null $device_id
 * @property string|null $cohort
 * @property list<int>|null $device_ids
 * @property string $bundle_identifier
 * @property string $apple_profile_id
 * @property string $uuid
 * @property string $name
 * @property string $status
 * @property Carbon|null $expires_at
 * @property string $content_encrypted Base64 .mobileprovision.
 */
class SigningProfile extends Model
{
    use HasPublicId;

    protected $fillable = [
        'apple_team_id', 'certificate_id', 'device_id', 'cohort', 'device_ids', 'bundle_identifier', 'apple_profile_id',
        'uuid', 'name', 'status', 'expires_at', 'content_encrypted',
    ];

    protected $hidden = ['content_encrypted'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'content_encrypted' => 'encrypted', 'device_ids' => 'array'];
    }

    public function isShared(): bool
    {
        return $this->device_id === null;
    }

    /** Whether the device is listed in this profile, so a build embedding it installs there. */
    public function covers(Device $device): bool
    {
        return $this->isShared()
            ? in_array($device->id, array_map('intval', $this->device_ids ?? []), true)
            : $this->device_id === $device->id;
    }

    public function isUsable(): bool
    {
        return $this->status === 'ACTIVE' && ($this->expires_at === null || $this->expires_at->gt(now()->addDay()));
    }

    /**
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /**
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }
}
