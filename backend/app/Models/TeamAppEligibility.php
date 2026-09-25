<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team is allowed to distribute a bundle ID, with evidence and approver (P7-BE-03).
 *
 * @property int $apple_team_id
 * @property string $bundle_identifier
 * @property string $evidence
 * @property string $status
 * @property Carbon $approved_at
 */
class TeamAppEligibility extends Model
{
    use HasPublicId;

    protected $fillable = ['apple_team_id', 'bundle_identifier', 'evidence', 'status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public static function allows(int $teamId, string $bundleIdentifier): bool
    {
        return static::query()->where(['apple_team_id' => $teamId, 'bundle_identifier' => $bundleIdentifier, 'status' => 'APPROVED'])->exists();
    }

    /**
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
