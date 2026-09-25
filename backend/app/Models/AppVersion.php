<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AppVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $released_at
 */
class AppVersion extends Model
{
    /** @use HasFactory<AppVersionFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['app_id', 'version', 'build_number', 'release_notes', 'min_ios_version', 'released_at'];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CatalogApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }
}
