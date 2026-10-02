<?php

namespace App\Models;

use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\Concerns\HasPublicId;
use Database\Factories\AppArtifactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property ArtifactStatus $status
 * @property string $sha256
 * @property int $size_bytes
 * @property string $storage_path
 * @property string $original_filename
 * @property SourceType $source_type
 * @property string|null $status_reason
 * @property string|null $bundle_identifier
 * @property string|null $version
 * @property string|null $build_number
 * @property string|null $min_ios_version
 * @property array<string, mixed>|null $inspection
 * @property int|null $app_version_id
 * @property int|null $derived_from_artifact_id
 * @property array<string, mixed>|null $cleaning_report
 * @property Carbon $declaration_accepted_at
 *
 * An original uploaded IPA. Identity and declaration columns are immutable at
 * the database level; only status and inspection results change.
 */
class AppArtifact extends Model
{
    /** @use HasFactory<AppArtifactFactory> */
    use HasFactory, HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'UPLOADED',
    ];

    protected $fillable = [
        'app_id', 'app_version_id', 'sha256', 'size_bytes', 'storage_disk', 'storage_path', 'original_filename',
        'source_type', 'uploaded_by', 'declaration_version', 'declaration_accepted_at', 'declaration_ip',
        'status', 'status_reason', 'bundle_identifier', 'version', 'build_number', 'min_ios_version', 'inspection',
        'derived_from_artifact_id', 'cleaning_report',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArtifactStatus::class,
            'source_type' => SourceType::class,
            'size_bytes' => 'integer',
            'declaration_accepted_at' => 'datetime',
            'inspection' => 'array',
            'cleaning_report' => 'array',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * The artifact this cleaned copy was made from (tools/ipa-cleaner).
     *
     * @return BelongsTo<AppArtifact, $this>
     */
    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(AppArtifact::class, 'derived_from_artifact_id');
    }

    /**
     * @return BelongsTo<CatalogApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }

    /**
     * @return BelongsTo<AppVersion, $this>
     */
    public function appVersion(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'app_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<ArtifactReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ArtifactReview::class, 'artifact_id')->orderBy('id');
    }

    /**
     * @return HasMany<ProvenanceDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProvenanceDocument::class, 'artifact_id')->orderBy('id');
    }

    /**
     * @return MorphMany<PipelineJob, $this>
     */
    public function pipelineJobs(): MorphMany
    {
        return $this->morphMany(PipelineJob::class, 'subject')->orderBy('id');
    }

    /**
     * The bundle ID signed builds carry: the listing's own when an operator set one
     * (a com.ruappstore.* ID for an app whose original ID belongs to another team),
     * otherwise the IPA's.
     */
    public function signingBundleIdentifier(): string
    {
        $listing = CatalogApp::withTrashed()->find($this->app_id);

        return (string) ($listing?->bundle_identifier ?: $this->bundle_identifier);
    }

    /**
     * App extensions and the bundle IDs they are signed with. An extension whose ID
     * extends the app's keeps its suffix under the signing ID
     * (org.example.app.tunnel → com.ruappstore.app.tunnel); any other keeps its last part.
     *
     * @return list<array{path: string, source_bundle_identifier: string, bundle_identifier: string, entitlements: array<string, mixed>}>
     */
    public function signingExtensions(): array
    {
        $original = (string) $this->bundle_identifier;
        $target = $this->signingBundleIdentifier();
        $appPath = rtrim((string) ($this->inspection['bundle']['path'] ?? ''), '/').'/';

        $extensions = [];
        foreach ($this->inspection['nested_bundles'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'extension' || ! is_string($item['bundle_identifier'] ?? null)) {
                continue;
            }
            $source = $item['bundle_identifier'];
            $extensions[] = [
                'path' => str_starts_with($item['path'], $appPath) ? substr($item['path'], strlen($appPath)) : $item['path'],
                'source_bundle_identifier' => $source,
                'bundle_identifier' => str_starts_with($source, $original.'.')
                    ? $target.substr($source, strlen($original))
                    : $target.'.'.substr((string) strrchr('.'.$source, '.'), 1),
                'entitlements' => is_array($item['entitlements'] ?? null) ? $item['entitlements'] : ($this->inspection['entitlements'] ?? []),
            ];
        }

        return $extensions;
    }
}
