<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An ad hoc provisioning profile for one (team, bundle ID, device)
 * (IMPLEMENTATION_PLAN D10). Profiles are recreated, never edited.
 *
 * @property int $apple_team_id
 * @property int $certificate_id
 * @property int $device_id
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
        'apple_team_id', 'certificate_id', 'device_id', 'bundle_identifier', 'apple_profile_id',
        'uuid', 'name', 'status', 'expires_at', 'content_encrypted',
    ];

    protected $hidden = ['content_encrypted'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'content_encrypted' => 'encrypted'];
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
