<?php

namespace App\Models;

use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\Concerns\HasPublicId;
use Database\Factories\AppArtifactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ArtifactStatus $status
 * @property string $sha256
 * @property int $size_bytes
 * @property string $storage_path
 * @property string $original_filename
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
    ];

    protected function casts(): array
    {
        return [
            'status' => ArtifactStatus::class,
            'source_type' => SourceType::class,
            'size_bytes' => 'integer',
            'declaration_accepted_at' => 'datetime',
            'inspection' => 'array',
            'purged_at' => 'datetime',
        ];
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
}
