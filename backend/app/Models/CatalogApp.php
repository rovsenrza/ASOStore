<?php

namespace App\Models;

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\Concerns\HasPublicId;
use Database\Factories\CatalogAppFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A catalog listing. Named CatalogApp to avoid clashing with the App facade
 * alias; the table is "apps" as in FULL_PLAN §7.
 *
 * @property \App\Enums\SourceType $source_type
 * @property \App\Enums\AppVisibility $visibility
 * @property bool $is_storefront
 * @property int|null $featured_rank
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CatalogApp extends Model
{
    /** @use HasFactory<CatalogAppFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected $table = 'apps';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'DRAFT',
        'age_rating' => '4+',
        'is_storefront' => false,
    ];

    protected $fillable = [
        'slug', 'name', 'subtitle', 'description', 'category_id', 'publisher_id',
        'source_type', 'visibility', 'age_rating', 'icon_path', 'is_storefront', 'featured_rank',
        'support_url', 'privacy_url',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'visibility' => AppVisibility::class,
            'is_storefront' => 'boolean',
            'featured_rank' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AppCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AppCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<AppPublisher, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(AppPublisher::class, 'publisher_id');
    }

    /**
     * @return HasMany<AppVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class, 'app_id');
    }

    /**
     * @return HasOne<AppVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(AppVersion::class, 'app_id')->ofMany([
            'released_at' => 'max',
            'id' => 'max',
        ]);
    }

    /**
     * @return HasMany<AppScreenshot, $this>
     */
    public function screenshots(): HasMany
    {
        return $this->hasMany(AppScreenshot::class, 'app_id')->orderBy('sort_order')->orderBy('id');
    }

    public function iconUrl(): ?string
    {
        return $this->icon_path ? Storage::disk('public')->url($this->icon_path) : null;
    }

    /**
     * @return HasMany<AppArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(AppArtifact::class, 'app_id');
    }

    /**
     * @return HasOne<AppArtifact, $this>
     */
    public function publishedArtifact(): HasOne
    {
        return $this->hasOne(AppArtifact::class, 'app_id')
            ->ofMany(['id' => 'max'], fn (Builder $query) => $query->where('status', ArtifactStatus::Published->value));
    }

    /**
     * Listings customers may see.
     *
     * @param  Builder<CatalogApp>  $query
     */
    public function scopeVisibleToCustomers(Builder $query): void
    {
        $query->where('visibility', AppVisibility::Published->value);
    }
}
