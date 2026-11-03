<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The core of an artifact's delta v3 builds (docs/adr/DELTA_V3.md): the re-signed Mach-O bytes outside each
 * team's variable ranges, stored once at cores/<sha256>.bin on the `signed` disk and shared by every team's
 * `delta-v2` pack. Several may exist per artifact (two first builds racing); builds name theirs.
 *
 * @property int $id
 * @property int $artifact_id
 * @property string $sha256
 * @property int $size_bytes
 * @property array<string, mixed> $manifest
 * @property string $storage_path
 */
class DeltaCore extends Model
{
    protected $fillable = ['artifact_id', 'sha256', 'size_bytes', 'manifest', 'storage_path'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'manifest' => 'array',
        ];
    }

    public static function pathFor(string $sha256): string
    {
        return 'cores/'.$sha256.'.bin';
    }

    /**
     * @return BelongsTo<AppArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AppArtifact::class, 'artifact_id');
    }

    /**
     * @return HasMany<SignedBuild, $this>
     */
    public function builds(): HasMany
    {
        return $this->hasMany(SignedBuild::class, 'delta_core_id');
    }
}
