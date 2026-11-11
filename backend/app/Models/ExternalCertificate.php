<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reseller-bought ("instant") certificate for one device: the profile it signs with and where
 * it came from. The private key lives only in the external runner's identities folder.
 *
 * @property string $status ACTIVE | REVOKED | EXPIRED
 * @property Carbon|null $profile_expires_at
 * @property Carbon|null $warranty_until
 */
class ExternalCertificate extends Model
{
    protected $fillable = [
        'apple_team_id', 'device_id', 'certificate_id', 'udid_hash', 'provider', 'provider_certificate_id', 'plan',
        'common_name', 'sha1_fingerprint', 'profile_uuid', 'profile_encrypted', 'profile_expires_at', 'warranty_until',
        'status', 'checked_at',
    ];

    protected $hidden = ['profile_encrypted'];

    protected function casts(): array
    {
        return [
            'profile_encrypted' => 'encrypted',
            'profile_expires_at' => 'datetime',
            'warranty_until' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === 'ACTIVE' && ($this->profile_expires_at === null || $this->profile_expires_at->gt(now()->addDay()));
    }

    /** @return BelongsTo<AppleTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }
}
