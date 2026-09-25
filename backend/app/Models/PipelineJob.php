<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\PipelineJobStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\PipelineJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PipelineJob extends Model
{
    /** @use HasFactory<PipelineJobFactory> */
    use HasFactory, HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'QUEUED',
        'attempt' => 0,
        'max_attempts' => 3,
    ];

    protected $fillable = [
        'type', 'status', 'idempotency_key', 'attempt', 'max_attempts', 'actor_type', 'actor_id',
        'correlation_id', 'subject_type', 'subject_id', 'payload', 'available_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PipelineJobStatus::class,
            'actor_type' => ActorType::class,
            'payload' => 'array',
            'lease_expires_at' => 'datetime',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PipelineJobAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PipelineJobAttempt::class);
    }
}
