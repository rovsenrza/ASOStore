<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property SubscriptionStatus $status
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
class Subscription extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    protected $fillable = ['user_id', 'activation_code_id', 'plan', 'status', 'starts_at', 'ends_at'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ActivationCode, $this>
     */
    public function activationCode(): BelongsTo
    {
        return $this->belongsTo(ActivationCode::class);
    }
}
