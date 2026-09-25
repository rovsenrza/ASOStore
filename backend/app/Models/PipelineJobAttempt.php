<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineJobAttempt extends Model
{
    protected $fillable = [
        'pipeline_job_id', 'attempt', 'worker', 'started_at', 'finished_at',
        'result_code', 'error_class', 'error_message_redacted',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PipelineJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class, 'pipeline_job_id');
    }
}
