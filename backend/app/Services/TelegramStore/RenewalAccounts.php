<?php

namespace App\Services\TelegramStore;

use App\Models\ActivationCode;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The site accounts a Telegram customer can extend from the bot: those on which a code they
 * bought was redeemed, or which they renewed before. Nothing typed by the customer is looked up,
 * so the bot cannot be used to find out whether an email has an account.
 */
class RenewalAccounts
{
    private const LIMIT = 6;

    /** @return Collection<int, User> most recently used first */
    public function for(TelegramStoreCustomer $customer): Collection
    {
        $orders = TelegramStoreOrder::query()
            ->where('customer_id', $customer->id)
            ->where('status', TelegramStoreOrder::PAID)
            ->get(['public_id']);
        if ($orders->isEmpty()) {
            return collect();
        }

        $ids = ActivationCode::query()
            ->whereIn('note', $orders->map(fn (TelegramStoreOrder $order) => OrderService::codeNote($order)))
            ->whereNotNull('redeemed_by')
            ->latest('redeemed_at')
            ->pluck('redeemed_by')
            ->unique()
            ->take(self::LIMIT)
            ->values();
        $users = User::query()->with('activeSubscription')->whereKey($ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $users->get($id))->filter()->values();
    }

    public function find(TelegramStoreCustomer $customer, string $publicId): ?User
    {
        return $this->for($customer)->first(fn (User $user) => $user->public_id === $publicId);
    }

    /** When the access ended or ends; null for an unlimited or never started subscription. */
    public function lastEnd(User $user): ?CarbonInterface
    {
        return $user->subscriptions()->whereNotNull('ends_at')->latest('ends_at')->first()?->ends_at;
    }

    /** ru***@mail.ru: enough to recognise your own account, not to read someone's address. */
    public static function maskEmail(?string $email): string
    {
        [$name, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');

        return mb_substr($name, 0, 2).'***'.($domain !== '' ? '@'.$domain : '');
    }
}
