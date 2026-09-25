<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reviewer decision on an artifact. Append-only.
 *
 * @property string $decision
 * @property string $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property array<string, bool>|null $checklist
 * @property bool $scan_result_acknowledged
 * @property Carbon $created_at
 */
class ArtifactReview extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const RELEASED = 'RELEASED';

    protected $fillable = [
        'artifact_id', 'reviewer_id', 'decision', 'from_status', 'to_status', 'reason',
        'checklist_version', 'checklist', 'scan_result_acknowledged',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'scan_result_acknowledged' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
