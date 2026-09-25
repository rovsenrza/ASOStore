<?php

namespace App\Models;

use App\Enums\ActivationCodeStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The plaintext code exists only in the creation response; this row keeps its
 * SHA-256 and the last four characters for support.
 *
 * @property ActivationCodeStatus $status
 * @property int|null $duration_days
 * @property Carbon|null $expires_at
 * @property Carbon|null $redeemed_at
 * @property Carbon|null $revoked_at
 */
class ActivationCode extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ISSUED',
        'plan' => 'standard',
    ];

    protected $fillable = [
        'batch_id', 'code_hash', 'code_hint', 'status', 'plan', 'duration_days', 'expires_at', 'note', 'created_by',
    ];

    protected $hidden = ['id', 'code_hash'];

    protected function casts(): array
    {
        return [
            'status' => ActivationCodeStatus::class,
            'duration_days' => 'integer',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Status as customers and operators should see it right now.
     */
    public function effectiveStatus(): ActivationCodeStatus
    {
        if ($this->status === ActivationCodeStatus::Issued && $this->expires_at?->isPast()) {
            return ActivationCodeStatus::Expired;
        }

        return $this->status;
    }
}
