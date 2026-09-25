<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Timeline entry for an installation (admin installation timeline, FULL_PLAN §14).
 *
 * @property string $type
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class InstallationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['installation_id', 'type', 'ip', 'user_agent', 'request_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
