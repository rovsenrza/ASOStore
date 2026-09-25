<?php

namespace App\Models;

use App\Enums\InstallationStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One request to install one app on one device (IMPLEMENTATION_PLAN §5.6).
 *
 * @property InstallationStatus $status
 * @property string|null $status_reason
 * @property int $user_id
 * @property int $device_id
 * @property int $app_id
 * @property int $artifact_id
 * @property int|null $signed_build_id
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Installation extends Model
{
    use HasPublicId;

    /** States in which the installation is still moving and may be resumed. */
    public const ACTIVE = [
        InstallationStatus::Preparing, InstallationStatus::ReadyToInstall,
        InstallationStatus::Authorized, InstallationStatus::ManifestFetched,
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'PREPARING'];

    protected $fillable = ['user_id', 'device_id', 'app_id', 'artifact_id', 'signed_build_id', 'status', 'status_reason', 'delivered_at'];

    protected function casts(): array
    {
        return ['status' => InstallationStatus::class, 'delivered_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CatalogApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id')->withTrashed();
    }

    /**
     * @return BelongsTo<AppArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AppArtifact::class, 'artifact_id');
    }

    /**
     * @return BelongsTo<SignedBuild, $this>
     */
    public function signedBuild(): BelongsTo
    {
        return $this->belongsTo(SignedBuild::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<InstallationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InstallationEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<InstallAuthorization, $this>
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(InstallAuthorization::class);
    }
}
