<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $number
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_path
 */
class UploadChunk extends Model
{
    protected $fillable = ['number', 'size_bytes', 'sha256', 'storage_path'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<UploadSession, $this> */
    public function uploadSession(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class);
    }
}
