<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AppCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppCategory extends Model
{
    /** @use HasFactory<AppCategoryFactory> */
    use HasFactory, HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    protected $fillable = ['slug', 'title', 'subtitle', 'sort_order'];

    /**
     * @return HasMany<CatalogApp, $this>
     */
    public function apps(): HasMany
    {
        return $this->hasMany(CatalogApp::class, 'category_id');
    }
}
