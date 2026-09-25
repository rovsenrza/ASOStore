<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A macOS signing runner (IMPLEMENTATION_PLAN P6-RUN-01). It authenticates to
 * the worker API with an HMAC key; it never receives Apple API credentials.
 *
 * @property string $key_id
 * @property string $name
 * @property string $secret_encrypted
 * @property string $status
 * @property string|null $version
 * @property Carbon|null $last_heartbeat_at
 * @property string|null $last_ip
 * @property list<array<string, mixed>>|null $identities
 */
class Runner extends Model
{
    use HasPublicId;

    public const OFFLINE_AFTER_SECONDS = 180;

    protected $fillable = ['key_id', 'name', 'secret_encrypted', 'status', 'version', 'last_heartbeat_at', 'last_ip', 'identities'];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'last_heartbeat_at' => 'datetime',
            'identities' => 'array',
        ];
    }

    public function isOnline(): bool
    {
        return $this->last_heartbeat_at !== null && $this->last_heartbeat_at->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }
}
