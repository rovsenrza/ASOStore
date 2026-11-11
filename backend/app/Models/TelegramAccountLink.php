<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site account a Telegram customer proved is theirs (a code sent to its email, or a link
 * opened from the signed-in site), so the bot can extend it without a code.
 *
 * @property int $customer_id
 * @property int $user_id
 * @property string $via
 */
class TelegramAccountLink extends Model
{
    public const VIA_EMAIL = 'email_code';

    public const VIA_SITE = 'site';

    protected $fillable = ['customer_id', 'user_id', 'via'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
