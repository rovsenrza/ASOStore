<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Telegram user who opened the store bot. The balance is internal credit
 * (referral bonuses, admin adjustments); every change has a ledger row.
 *
 * @property int $telegram_user_id
 * @property int $chat_id
 * @property int $balance_rub
 * @property int $referral_earned_rub
 * @property Carbon|null $blocked_at
 */
class TelegramStoreCustomer extends Model
{
    protected $fillable = [
        'telegram_user_id', 'chat_id', 'username', 'first_name', 'referral_code', 'referrer_id', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'chat_id' => 'integer',
            'balance_rub' => 'integer',
            'referral_earned_rub' => 'integer',
            'blocked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    /** @return HasMany<self, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    /** @return HasMany<TelegramStoreOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(TelegramStoreOrder::class, 'customer_id');
    }

    public function displayName(): string
    {
        return $this->username ? '@'.$this->username : ($this->first_name ?: (string) $this->telegram_user_id);
    }
}
