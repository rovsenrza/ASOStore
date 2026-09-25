<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AppPublisherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppPublisher extends Model
{
    /** @use HasFactory<AppPublisherFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['name', 'website', 'support_email'];

    /**
     * @return HasMany<CatalogApp, $this>
     */
    public function apps(): HasMany
    {
        return $this->hasMany(CatalogApp::class, 'publisher_id');
    }
}
