<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Enums\PipelineJobStatus;
use App\Models\PipelineJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PipelineJob>
 */
class PipelineJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'InspectArtifactJob',
            'status' => PipelineJobStatus::Queued,
            'idempotency_key' => 'test:'.Str::uuid(),
            'actor_type' => ActorType::System,
            'correlation_id' => strtolower((string) Str::ulid()),
        ];
    }
}
