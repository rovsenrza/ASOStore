<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Write through AuditService; the database rejects updates and
 * deletes, and the model refuses them earlier with a clearer error.
 *
 * @property CarbonImmutable $occurred_at
 * @property ActorType $actor_type
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
class AuditLog extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'occurred_at', 'actor_type', 'actor_id', 'actor_label', 'action', 'subject_type', 'subject_id',
        'before', 'after', 'reason', 'request_id', 'correlation_id', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'actor_type' => ActorType::class,
            'before' => 'array',
            'after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are append-only.'));
    }
}
