<?php

namespace App\Services\TelegramStore;

use App\Models\ActivationCode;
use App\Models\TelegramAccountLink;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Links a site account to the Telegram bot buyers behind it: whoever bought a code that was
 * redeemed on the account, or renewed it from the bot (renew_user_id).
 */
class AccountContacts
{
    public const NOTE_PREFIX = 'Telegram Store order ';

    /**
     * Every store order that belongs to the account (bot orders by code or renewal, and website orders).
     *
     * @return Builder<TelegramStoreOrder>
     */
    public function orders(User $user): Builder
    {
        $orderIds = $this->redeemedOrderIds($user);

        return TelegramStoreOrder::query()->where(fn (Builder $query) => $query
            ->where('user_id', $user->id)
            ->orWhere('renew_user_id', $user->id)
            ->orWhereIn('public_id', $orderIds->all()));
    }

    /**
     * Distinct Telegram buyers of the account, newest payment first.
     *
     * @return Collection<int, array{telegram_user_id: int, username: ?string, first_name: ?string}>
     */
    public function telegram(User $user): Collection
    {
        $orderIds = $this->redeemedOrderIds($user);

        // Chats that proved the account is theirs (email code, the signed-in site, an operator),
        // whether or not they bought anything in the bot.
        $linked = TelegramStoreCustomer::query()
            ->whereIn('id', TelegramAccountLink::query()->where('user_id', $user->id)->select('customer_id'))
            ->get(['telegram_user_id', 'username', 'first_name'])
            ->map(fn (TelegramStoreCustomer $customer) => [
                'telegram_user_id' => (int) $customer->telegram_user_id,
                'username' => $customer->username,
                'first_name' => $customer->first_name,
            ]);

        return TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PAID)
            ->whereNotNull('telegram_user_id')
            ->where(fn (Builder $query) => $query->where('renew_user_id', $user->id)->orWhereIn('public_id', $orderIds->all()))
            ->latest('paid_at')
            ->get(['telegram_user_id', 'username', 'first_name'])
            ->unique('telegram_user_id')
            ->map(fn (TelegramStoreOrder $order) => [
                'telegram_user_id' => (int) $order->telegram_user_id,
                'username' => $order->username,
                'first_name' => $order->first_name,
            ])
            ->toBase()
            ->concat($linked)
            ->unique('telegram_user_id')
            ->values();
    }

    /** Accounts whose bot buyer matches a Telegram @username or numeric id. */
    public function usersForTelegram(string $query): Collection
    {
        $query = ltrim(trim($query), '@');
        if ($query === '') {
            return collect();
        }
        $orders = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::PAID)
            ->where(fn (Builder $where) => ctype_digit($query)
                ? $where->where('telegram_user_id', (int) $query)
                : $where->where('username', $query))
            ->get(['public_id', 'renew_user_id', 'user_id']);

        $notes = $orders->map(fn (TelegramStoreOrder $order) => self::NOTE_PREFIX.strtoupper($order->public_id));
        $redeemers = ActivationCode::query()->whereIn('note', $notes->all())->whereNotNull('redeemed_by')->pluck('redeemed_by');

        $linked = TelegramAccountLink::query()
            ->whereIn('customer_id', TelegramStoreCustomer::query()->where(fn (Builder $where) => ctype_digit($query)
                ? $where->where('telegram_user_id', (int) $query)
                : $where->where('username', $query))->select('id'))
            ->pluck('user_id');

        return $orders->pluck('renew_user_id')->merge($orders->pluck('user_id'))->merge($redeemers)->merge($linked)->filter()->unique()->values();
    }

    /** @return Collection<int, string> lower-case order public ids */
    private function redeemedOrderIds(User $user): Collection
    {
        return ActivationCode::query()->where('redeemed_by', $user->id)
            ->where('note', 'like', self::NOTE_PREFIX.'%')->pluck('note')
            ->map(fn (string $note) => strtolower(substr($note, strlen(self::NOTE_PREFIX))));
    }
}
