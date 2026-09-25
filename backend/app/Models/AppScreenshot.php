<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AppScreenshot extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    protected $fillable = ['app_id', 'path', 'width', 'height', 'sort_order'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CatalogApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * @return array{id: string, url: string, width: int, height: int}
     */
    public function present(): array
    {
        return ['id' => $this->public_id, 'url' => $this->url(), 'width' => $this->width, 'height' => $this->height];
    }
}
