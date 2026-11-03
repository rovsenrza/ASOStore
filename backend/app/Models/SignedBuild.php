<?php

namespace App\Models;

use App\Enums\SignedBuildStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An original artifact re-signed for one device profile (IMPLEMENTATION_PLAN G6), or
 * shared by an Apple team (device_id null): signed once with a profile listing the
 * team's devices, it serves every device that profile lists.
 *
 * @property SignedBuildStatus $status
 * @property string|null $status_reason
 * @property int $artifact_id
 * @property int|null $device_id
 * @property int|null $apple_team_id
 * @property int|null $signing_profile_id
 * @property int|null $certificate_id
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property string|null $storage_path
 * @property array<string, mixed>|null $signing_report
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $purged_at
 */
class SignedBuild extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'SIGNING_PENDING'];

    public const FORMAT_FULL = 'full';

    public const FORMAT_DELTA = 'delta-v1';

    /** A pack whose re-signed Mach-Os are segmented over a shared core (docs/adr/DELTA_V3.md). */
    public const FORMAT_DELTA_V2 = 'delta-v2';

    protected $fillable = [
        'artifact_id', 'device_id', 'apple_team_id', 'signing_profile_id', 'certificate_id', 'status', 'status_reason',
        'format', 'delivered_size', 'sha256', 'size_bytes', 'storage_path', 'signing_report', 'signed_at', 'verified_at', 'expires_at', 'last_used_at',
        'bootstrap_claim_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'status' => SignedBuildStatus::class,
            'size_bytes' => 'integer',
            'delivered_size' => 'integer',
            'signing_report' => 'array',
            'signed_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'purged_at' => 'datetime',
            'bootstrap_claim_encrypted' => 'encrypted',
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

    /** Whether storage_path holds a pack to compose the IPA from, not the IPA itself. */
    public function isDelta(): bool
    {
        return in_array($this->format, [self::FORMAT_DELTA, self::FORMAT_DELTA_V2], true);
    }

    /** The byte size a client downloads: the composed IPA for a delta build, the stored file otherwise. */
    public function deliveredBytes(): int
    {
        return (int) ($this->isDelta() ? $this->delivered_size : $this->size_bytes);
    }

    public function isShared(): bool
    {
        return $this->device_id === null;
    }

    /**
     * Whether this build can be installed on the device. A shared build still waiting for
     * its profile serves every device of its team: the profile is made for the devices
     * eligible when preparation runs.
     */
    public function serves(Device $device): bool
    {
        if (! $this->isShared()) {
            return $this->device_id === $device->id;
        }
        if ($this->signing_profile_id === null) {
            return $this->apple_team_id !== null && $device->latestRegistration?->apple_team_id === $this->apple_team_id;
        }

        return $this->profile?->covers($device) ?? false;
    }

    /** Marks the build as wanted, so it is not reclaimed as idle (StorageJanitor). At most once a minute. */
    public function markUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    /**
     * @return HasMany<Installation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
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
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
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

    /**
     * The shared core a `delta-v2` pack's "c" segments point into (docs/adr/DELTA_V3.md).
     *
     * @return BelongsTo<DeltaCore, $this>
     */
    public function deltaCore(): BelongsTo
    {
        return $this->belongsTo(DeltaCore::class, 'delta_core_id');
    }
}
