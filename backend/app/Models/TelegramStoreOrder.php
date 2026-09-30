<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PENDING → REVIEW (customer says they paid) → PAID, or CANCELLED / EXPIRED /
 * REJECTED. Balance held by an order goes back when it does not complete.
 *
 * @property string $public_id
 * @property int $price_rub
 * @property int $discount_rub
 * @property int $balance_used_rub
 * @property int $amount_due_rub
 * @property int $duration_days
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 */
class TelegramStoreOrder extends Model
{
    public const PENDING = 'PENDING';

    public const REVIEW = 'REVIEW';

    public const PAID = 'PAID';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    public const REJECTED = 'REJECTED';

    protected $guarded = ['id'];

    protected $hidden = ['activation_code'];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'chat_id' => 'integer',
            'duration_days' => 'integer',
            'price_rub' => 'integer',
            'discount_rub' => 'integer',
            'promo_code_id' => 'integer',
            'balance_used_rub' => 'integer',
            'amount_due_rub' => 'integer',
            'referral_bonus_rub' => 'integer',
            'reviewed_by' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TelegramStoreCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(TelegramStoreCustomer::class, 'customer_id');
    }

    /** @return BelongsTo<TelegramStorePromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(TelegramStorePromoCode::class, 'promo_code_id');
    }

    public function isPayable(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }

    /** Full ULID, upper-case, as shown to customers and admins. */
    public function reference(): string
    {
        return strtoupper($this->public_id);
    }

    public function shortReference(): string
    {
        return substr($this->reference(), -8);
    }
}
