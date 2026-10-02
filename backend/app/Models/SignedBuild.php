<?php

namespace App\Models;

use App\Enums\SignedBuildStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An original artifact re-signed for one device profile (IMPLEMENTATION_PLAN G6).
 *
 * @property SignedBuildStatus $status
 * @property string|null $status_reason
 * @property int $artifact_id
 * @property int $device_id
 * @property int|null $signing_profile_id
 * @property int|null $certificate_id
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property string|null $storage_path
 * @property array<string, mixed>|null $signing_report
 * @property Carbon|null $expires_at
 */
class SignedBuild extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'SIGNING_PENDING'];

    protected $fillable = [
        'artifact_id', 'device_id', 'signing_profile_id', 'certificate_id', 'status', 'status_reason',
        'sha256', 'size_bytes', 'storage_path', 'signing_report', 'signed_at', 'verified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SignedBuildStatus::class,
            'size_bytes' => 'integer',
            'signing_report' => 'array',
            'signed_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * How far preparation got, for the customer's progress bar. Waiting for the Apple
     * profile and waiting for a runner are both SIGNING_PENDING; the profile tells them apart.
     */
    public function progress(): ?float
    {
        return match ($this->status) {
            SignedBuildStatus::SigningPending => $this->signing_profile_id === null ? 0.15 : 0.35,
            SignedBuildStatus::Signing => 0.5,
            SignedBuildStatus::Signed => 0.8,
            SignedBuildStatus::SignatureVerified => 0.9,
            SignedBuildStatus::Deliverable => 1.0,
            default => null,
        };
    }

    /**
     * Reuse requires the embedded profile and signing certificate to remain valid.
     */
    public function isDeliverable(): bool
    {
        if ($this->status !== SignedBuildStatus::Deliverable || ($this->expires_at !== null && $this->expires_at->isPast())) {
            return false;
        }
        $profile = $this->profile;
        if ($this->signing_profile_id !== null && ($profile === null || $profile->status !== 'ACTIVE'
            || ($profile->expires_at !== null && ! $profile->expires_at->isFuture()))) {
            return false;
        }
        $certificate = $this->certificate;

        return $certificate === null
            ? $this->certificate_id === null
            : $certificate->status === 'ACTIVE' && ($certificate->expires_at === null || $certificate->expires_at->isFuture());
    }

    /**
     * @return BelongsTo<AppArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AppArtifact::class, 'artifact_id');
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<SigningProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SigningProfile::class, 'signing_profile_id');
    }

    /**
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }
}
