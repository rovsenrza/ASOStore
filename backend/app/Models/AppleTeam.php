<?php

namespace App\Models;

use App\Enums\AppleTeamStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An Apple Developer team the business legitimately controls (FULL_PLAN §1.3).
 *
 * @property AppleTeamStatus $status
 * @property Carbon|null $membership_expires_at
 * @property Carbon|null $last_verified_at
 */
class AppleTeam extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'PENDING_VERIFICATION',
        'is_primary' => false,
    ];

    protected $fillable = ['apple_team_id', 'name', 'status', 'is_primary', 'membership_expires_at', 'storefront_app_id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AppleTeamStatus::class,
            'is_primary' => 'boolean',
            'membership_expires_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AppleCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(AppleCredential::class);
    }

    /**
     * @return BelongsTo<CatalogApp, $this>
     */
    public function storefrontApp(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'storefront_app_id');
    }

    /**
     * @return HasOne<AppleCredential, $this>
     */
    public function activeCredential(): HasOne
    {
        return $this->hasOne(AppleCredential::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('status', 'ACTIVE'));
    }

    /**
     * @return HasMany<MembershipYear, $this>
     */
    public function membershipYears(): HasMany
    {
        return $this->hasMany(MembershipYear::class);
    }

    public function currentMembershipYear(): ?MembershipYear
    {
        return $this->membershipYears()
            ->where('status', 'ACTIVE')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->latest('starts_at')
            ->first();
    }

    /**
     * The team Phase 3 registers devices with. Multi-team selection is Phase 7.
     */
    public static function primary(): ?self
    {
        return static::query()->where('is_primary', true)->first();
    }
}
