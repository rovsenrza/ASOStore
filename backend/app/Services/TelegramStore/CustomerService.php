<?php

namespace App\Services\TelegramStore;

use App\Models\TelegramStoreCustomer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class CustomerService
{
    /**
     * Creates or refreshes the customer behind a Telegram update. A new
     * customer arriving through "/start ref_<code>" is bound to that referrer;
     * the referrer is returned so they can be told.
     *
     * @param  array<string, mixed>  $from
     * @return array{0: TelegramStoreCustomer, 1: TelegramStoreCustomer|null}
     */
    public function sync(array $from, int $chatId, ?string $startPayload = null): array
    {
        $userId = (int) $from['id'];
        $profile = [
            'chat_id' => $chatId,
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_seen_at' => now(),
        ];

        $customer = TelegramStoreCustomer::query()->where('telegram_user_id', $userId)->first();
        if ($customer) {
            $customer->fill($profile);
            $customer->blocked_at = null;
            $customer->save();

            return [$customer, null];
        }

        $referrer = null;
        if ($startPayload !== null && preg_match('/^ref_([a-z0-9]{6,16})$/', $startPayload, $match)) {
            $referrer = TelegramStoreCustomer::query()->where('referral_code', $match[1])->first();
        }

        try {
            $customer = TelegramStoreCustomer::create($profile + [
                'telegram_user_id' => $userId,
                'referral_code' => $this->newReferralCode(),
                'referrer_id' => $referrer?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel update created the customer first.
            return [TelegramStoreCustomer::query()->where('telegram_user_id', $userId)->firstOrFail(), null];
        }

        return [$customer, $referrer];
    }

    public function find(string $query): ?TelegramStoreCustomer
    {
        $query = trim($query);
        if (preg_match('/^\d+$/', $query)) {
            return TelegramStoreCustomer::query()->where('telegram_user_id', (int) $query)->first();
        }

        return TelegramStoreCustomer::query()->where('username', ltrim($query, '@'))->first();
    }

    private function newReferralCode(): string
    {
        do {
            $code = strtolower(Str::random(8));
        } while (TelegramStoreCustomer::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
