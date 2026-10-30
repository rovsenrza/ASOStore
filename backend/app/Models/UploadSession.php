<?php

namespace App\Models;

use App\Enums\SourceType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $expected_size
 * @property string|null $expected_sha256
 * @property int $chunk_size
 * @property int $chunk_count
 * @property SourceType $source_type
 * @property string $status
 * @property Carbon $declaration_accepted_at
 * @property Carbon $expires_at
 * @property-read AppArtifact|null $artifact
 */
class UploadSession extends Model
{
    use HasPublicId;

    public const CHUNK_SIZE = 2 * 1024 * 1024;

    /**
     * Chunk size for new upload sessions. Clients (iOS app, admin) read it from the session, so it
     * can differ per environment: the test suite uses small chunks to keep PHP within 128M.
     */
    /**
     * Credential for the token chunk route (PUT /import-uploads/{id}/chunks/{n}). The app hands chunks
     * to an iOS background session that keeps sending them after the app is suspended or closed, long
     * after the customer's access token has expired; this ties a request to one upload instead.
     * Valid while the session is open (expires_at).
     */
    public function clientToken(): string
    {
        return hash_hmac('sha256', 'import-upload|'.$this->public_id, (string) config('app.key'));
    }

    public static function chunkSize(): int
    {
        return max(1024, (int) config('storefront.uploads.chunk_bytes', self::CHUNK_SIZE));
    }

    protected $fillable = [
        'app_id', 'app_version_id', 'artifact_id', 'uploaded_by', 'original_filename',
        'expected_size', 'expected_sha256', 'chunk_size', 'chunk_count', 'source_type',
        'declaration_version', 'declaration_accepted_at', 'declaration_ip', 'status',
        'failure_reason', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'expected_size' => 'integer',
            'chunk_size' => 'integer',
            'chunk_count' => 'integer',
            'declaration_accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }

    /** @return BelongsTo<AppVersion, $this> */
    public function appVersion(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'app_version_id');
    }

    /** @return BelongsTo<AppArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AppArtifact::class, 'artifact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<UploadChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(UploadChunk::class);
    }
}
