<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Optional evidence attached to an artifact's provenance review (license,
 * partner agreement, build log). Stored on the private artifacts disk.
 *
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_path
 * @property string|null $description
 * @property Carbon $created_at
 */
class ProvenanceDocument extends Model
{
    use HasPublicId;

    protected $fillable = [
        'artifact_id', 'uploaded_by', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'storage_path', 'description',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<AppArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AppArtifact::class, 'artifact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
