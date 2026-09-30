<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code: a percentage or a fixed amount off the plan price, used
 * once per customer. Uses are counted from the orders that hold the code.
 *
 * @property string $code
 * @property string $type
 * @property int $value
 * @property int|null $max_uses
 * @property list<string>|null $plan_keys
 * @property Carbon|null $expires_at
 * @property bool $active
 */
class TelegramStorePromoCode extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_uses' => 'integer',
            'plan_keys' => 'array',
            'expires_at' => 'datetime',
            'active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /** @return HasMany<TelegramStoreOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(TelegramStoreOrder::class, 'promo_code_id');
    }

    public function discountFor(int $price): int
    {
        return $this->type === self::PERCENT
            ? intdiv($price * min($this->value, 100), 100)
            : min($this->value, $price);
    }

    public function label(): string
    {
        return $this->type === self::PERCENT ? "−{$this->value}%" : '−'.number_format($this->value, 0, '', ' ').'₽';
    }
}
