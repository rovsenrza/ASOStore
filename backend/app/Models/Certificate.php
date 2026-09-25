<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Signing certificate metadata. The private key exists only in a runner's
 * Keychain (IMPLEMENTATION_PLAN D8).
 *
 * @property int $apple_team_id
 * @property string $sha1_fingerprint
 * @property string $serial_number
 * @property string $common_name
 * @property string|null $apple_certificate_id
 * @property string $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_seen_at
 */
class Certificate extends Model
{
    use HasPublicId;

    protected $fillable = [
        'apple_team_id', 'sha1_fingerprint', 'serial_number', 'common_name', 'apple_certificate_id',
        'status', 'expires_at', 'runner_id', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AppleTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }

    /**
     * @return BelongsTo<Runner, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class);
    }
}
