<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $attempt
 * @property string|null $worker
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $result_code
 * @property string|null $error_class
 * @property string|null $error_message_redacted
 */
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
