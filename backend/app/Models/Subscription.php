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

    protected $fillable = ['user_id', 'device_id', 'activation_code_id', 'plan', 'status', 'starts_at', 'ends_at'];

    protected $hidden = ['id'];

    /**
     * Access is paid per iPhone, so every subscription belongs to a device. Code that creates one
     * without saying which (older paths, admin tools) gets the obvious one: the account's only
     * device, or a new pending device when it has none. With several devices it must say which.
     */
    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription) {
            if ($subscription->device_id !== null || $subscription->user_id === null) {
                return;
            }
            $devices = Device::query()->where('user_id', $subscription->user_id)->orderBy('id')->limit(2)->pluck('id');
            if ($devices->count() > 1) {
                \Illuminate\Support\Facades\Log::warning('subscription.device_missing', ['user' => $subscription->user_id]);

                return;
            }
            $subscription->device_id = $devices->first()
                ?? Device::query()->create(['user_id' => $subscription->user_id])->id;
        });
    }

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
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<ActivationCode, $this>
     */
    public function activationCode(): BelongsTo
    {
        return $this->belongsTo(ActivationCode::class);
    }
}
