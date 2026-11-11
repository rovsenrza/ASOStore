<?php

namespace App\Services\TelegramStore;

use App\Enums\UserStatus;
use App\Models\TelegramAccountLink;
use App\Models\TelegramStoreCustomer;
use App\Models\User;
use App\Notifications\TelegramLinkCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets a Telegram customer prove which site account is theirs, so the bot extends that account
 * instead of selling a code that ends up on a second one.
 *
 * Two proofs: a six-digit code sent to the account's email and typed into the chat, or a
 * one-time link opened from the signed-in site. The bot never says whether an email has an
 * account: the answer to «send me a code» is the same either way.
 */
class AccountLinker
{
    public const CODE_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    private const TOKEN_MINUTES = 30;

    /**
     * Sends the code if the email belongs to an active account. Returns false only when the
     * customer asked too often; true says nothing about the email.
     */
    public function requestCode(TelegramStoreCustomer $customer, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        // Three tries per chat and per mailbox in half an hour: the bot is not a way to flood someone's inbox.
        if (RateLimiter::tooManyAttempts($chatKey = "tg-link:chat:{$customer->id}", 3)
            || RateLimiter::tooManyAttempts($mailKey = 'tg-link:mail:'.sha1($email), 3)) {
            return false;
        }
        RateLimiter::hit($chatKey, 1800);
        RateLimiter::hit($mailKey, 1800);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->where('status', UserStatus::Active->value)->first();
        if ($user === null) {
            Cache::forget($this->codeKey($customer));

            return true;
        }

        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($customer), ['user' => $user->id, 'hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes(self::CODE_MINUTES));
        try {
            $user->notify(new TelegramLinkCodeNotification($code, self::CODE_MINUTES));
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }

    /** The account the typed code confirms, linked to the customer; null when the code is wrong or stale. */
    public function confirm(TelegramStoreCustomer $customer, string $input): ?User
    {
        $pending = Cache::get($this->codeKey($customer));
        $code = preg_replace('/\D+/', '', $input) ?? '';
        if (! is_array($pending) || strlen($code) !== 6) {
            return null;
        }
        if (! hash_equals((string) $pending['hash'], hash('sha256', $code))) {
            $pending['attempts']++;
            $pending['attempts'] >= self::MAX_ATTEMPTS
                ? Cache::forget($this->codeKey($customer))
                : Cache::put($this->codeKey($customer), $pending, now()->addMinutes(self::CODE_MINUTES));

            return null;
        }
        Cache::forget($this->codeKey($customer));
        $user = User::query()->where('status', UserStatus::Active->value)->find($pending['user']);

        return $user === null ? null : $this->link($customer, $user, TelegramAccountLink::VIA_EMAIL);
    }

    /** Whether a code is waiting to be typed (it may have been used up by wrong attempts). */
    public function awaitsCode(TelegramStoreCustomer $customer): bool
    {
        return is_array(Cache::get($this->codeKey($customer)));
    }

    public function link(TelegramStoreCustomer $customer, User $user, string $via): User
    {
        TelegramAccountLink::query()->updateOrCreate(['customer_id' => $customer->id, 'user_id' => $user->id], ['via' => $via])->touch();

        return $user;
    }

    /** A one-time token for a signed-in site visitor: `t.me/<bot>?start=acc_<token>` links their account. */
    public function siteToken(User $user): string
    {
        $token = Str::lower(Str::random(40));
        Cache::put("tg-link:token:{$token}", $user->id, now()->addMinutes(self::TOKEN_MINUTES));

        return $token;
    }

    /** The account behind a site token, linked to the customer; null when the token is unknown or used. */
    public function consumeSiteToken(TelegramStoreCustomer $customer, string $token): ?User
    {
        $id = Cache::pull('tg-link:token:'.Str::lower($token));
        $user = $id === null ? null : User::query()->where('status', UserStatus::Active->value)->find($id);

        return $user === null ? null : $this->link($customer, $user, TelegramAccountLink::VIA_SITE);
    }

    private function codeKey(TelegramStoreCustomer $customer): string
    {
        return "tg-link:code:{$customer->id}";
    }
}
