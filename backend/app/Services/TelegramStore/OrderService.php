<?php

namespace App\Services\TelegramStore;

use App\Enums\RoleSlug;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\Activation\ActivationCodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class OrderService
{
    public function __construct(
        private readonly StoreSettings $settings,
        private readonly BalanceLedger $ledger,
        private readonly ActivationCodeService $codes,
    ) {}

    /**
     * Opens an order for a plan. Older unpaid orders of the customer are
     * cancelled so only one checkout is live at a time.
     */
    public function create(TelegramStoreCustomer $customer, string $planKey): TelegramStoreOrder
    {
        $plan = $this->settings->plan($planKey) ?? throw new InvalidArgumentException("Unknown plan {$planKey}.");

        TelegramStoreOrder::query()
            ->where('customer_id', $customer->id)
            ->where('status', TelegramStoreOrder::PENDING)
            ->get()
            ->each(fn (TelegramStoreOrder $order) => $this->close($order, TelegramStoreOrder::CANCELLED));

        return TelegramStoreOrder::create([
            'public_id' => strtolower((string) Str::ulid()),
            'customer_id' => $customer->id,
            'telegram_user_id' => $customer->telegram_user_id,
            'chat_id' => $customer->chat_id,
            'username' => $customer->username,
            'first_name' => $customer->first_name,
            'plan' => 'standard',
            'plan_key' => $planKey,
            'duration_days' => $plan['days'],
            'price_rub' => $plan['price'],
            'amount_due_rub' => $plan['price'],
            'status' => TelegramStoreOrder::PENDING,
            'expires_at' => now()->addMinutes((int) config('telegram_store.order_ttl_minutes', 30)),
        ]);
    }

    /**
     * Opens a website order for a plan, paid online and delivered to the account.
     * Older unpaid website orders of the account are cancelled, as in the bot.
     */
    public function createForUser(User $user, string $planKey): TelegramStoreOrder
    {
        $plan = $this->settings->plan($planKey) ?? throw new InvalidArgumentException("Unknown plan {$planKey}.");

        TelegramStoreOrder::query()
            ->where('user_id', $user->id)
            ->whereNull('chat_id')
            ->where('status', TelegramStoreOrder::PENDING)
            ->get()
            ->each(fn (TelegramStoreOrder $order) => $this->close($order, TelegramStoreOrder::CANCELLED));

        return TelegramStoreOrder::create([
            'public_id' => strtolower((string) Str::ulid()),
            'user_id' => $user->id,
            'plan' => 'standard',
            'plan_key' => $planKey,
            'duration_days' => $plan['days'],
            'price_rub' => $plan['price'],
            'amount_due_rub' => $plan['price'],
            'status' => TelegramStoreOrder::PENDING,
            'expires_at' => now()->addMinutes((int) config('telegram_store.order_ttl_minutes', 30)),
        ]);
    }

    public function find(string $reference, ?int $telegramUserId = null): ?TelegramStoreOrder
    {
        return TelegramStoreOrder::query()
            ->where('public_id', strtolower($reference))
            ->when($telegramUserId !== null, fn (Builder $query) => $query->where('telegram_user_id', $telegramUserId))
            ->first();
    }

    /**
     * Moves as much balance as the order needs onto it. When the balance
     * covers the whole price the order completes straight away.
     */
    public function applyBalance(TelegramStoreOrder $order): ?CompletedOrder
    {
        $held = DB::transaction(function () use ($order) {
            $locked = TelegramStoreOrder::query()->lockForUpdate()->findOrFail($order->id);
            $customer = TelegramStoreCustomer::query()->lockForUpdate()->findOrFail($locked->customer_id);
            $amount = min($customer->balance_rub, $locked->amount_due_rub);
            if (! $locked->isPayable() || $amount <= 0) {
                return 0;
            }
            $this->ledger->change($customer->id, -$amount, BalanceLedger::ORDER_PAYMENT, $locked->id);
            $locked->update([
                'balance_used_rub' => $locked->balance_used_rub + $amount,
                'amount_due_rub' => $locked->amount_due_rub - $amount,
            ]);

            return $amount;
        });
        $order->refresh();

        if ($held > 0 && $order->amount_due_rub === 0) {
            return $this->complete($order, 'balance');
        }

        return null;
    }

    public function selectMethod(TelegramStoreOrder $order, string $method, string $provider): void
    {
        $order->update(['payment_method' => $method, 'payment_provider' => $provider]);
    }

    /** The customer says they paid; an admin has to verify it. */
    public function markForReview(TelegramStoreOrder $order): bool
    {
        return DB::table('telegram_store_orders')
            ->where('id', $order->id)
            ->where('status', TelegramStoreOrder::PENDING)
            ->where('expires_at', '>', now())
            ->update(['status' => TelegramStoreOrder::REVIEW, 'updated_at' => now()]) === 1;
    }

    /**
     * A closed order whose payment arrived anyway goes back to the admins: it
     * held balance that closing returned, so only they can settle it.
     */
    public function reopenForReview(TelegramStoreOrder $order): bool
    {
        $reopened = DB::table('telegram_store_orders')
            ->where('id', $order->id)
            ->whereIn('status', [TelegramStoreOrder::EXPIRED, TelegramStoreOrder::CANCELLED])
            ->update(['status' => TelegramStoreOrder::REVIEW, 'updated_at' => now()]) === 1;
        $order->refresh();

        return $reopened;
    }

    /**
     * Cancels, expires or rejects an order that has not been paid, returning held balance.
     */
    public function close(TelegramStoreOrder $order, string $status): bool
    {
        $closed = DB::transaction(function () use ($order, $status) {
            $locked = TelegramStoreOrder::query()->lockForUpdate()->findOrFail($order->id);
            $closable = $status === TelegramStoreOrder::REJECTED
                ? [TelegramStoreOrder::PENDING, TelegramStoreOrder::REVIEW]
                : [TelegramStoreOrder::PENDING];
            if (! in_array($locked->status, $closable, true)) {
                return false;
            }
            if ($locked->balance_used_rub > 0 && $locked->customer_id) {
                $this->ledger->change($locked->customer_id, $locked->balance_used_rub, BalanceLedger::ORDER_REFUND, $locked->id);
            }
            $locked->update(['status' => $status]);

            return true;
        });
        $order->refresh();

        return $closed;
    }

    /**
     * Expires unpaid orders whose payment window has passed. Orders the
     * customer reported as paid (REVIEW) wait for an admin instead.
     *
     * @return Collection<int, TelegramStoreOrder>
     */
    public function expireStale(): Collection
    {
        return TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PENDING)
            ->where('expires_at', '<=', now())
            ->limit(200)
            ->get()
            ->filter(fn (TelegramStoreOrder $order) => $this->close($order, TelegramStoreOrder::EXPIRED))
            ->values();
    }

    /**
     * Marks the order paid, issues its activation code and credits the
     * referrer. Returns null when the order is not in a payable state.
     *
     * An admin may confirm an order that is under review or still pending,
     * even after the payment window: the customer may have paid in time.
     * A payment the provider has $settled completes the same way, and also an
     * order closed meanwhile (new order opened, window passed) as long as it
     * held no balance, which closing has already given back.
     */
    public function complete(TelegramStoreOrder $order, string $provider, ?int $adminTelegramId = null, bool $settled = false, ?string $reference = null): ?CompletedOrder
    {
        $result = DB::transaction(function () use ($order, $provider, $adminTelegramId, $settled, $reference) {
            $locked = TelegramStoreOrder::query()->lockForUpdate()->findOrFail($order->id);
            $payable = match (true) {
                $adminTelegramId !== null => in_array($locked->status, [TelegramStoreOrder::PENDING, TelegramStoreOrder::REVIEW], true),
                $settled => in_array($locked->status, [TelegramStoreOrder::PENDING, TelegramStoreOrder::REVIEW], true)
                    || (in_array($locked->status, [TelegramStoreOrder::EXPIRED, TelegramStoreOrder::CANCELLED], true) && $locked->balance_used_rub === 0),
                default => $locked->isPayable(),
            };
            if (! $payable) {
                return null;
            }

            $issuer = User::query()
                ->whereHas('roles', fn (Builder $roles) => $roles->where('slug', RoleSlug::Admin->value))
                ->oldest('id')
                ->first() ?? throw new RuntimeException('No admin account is available to issue activation codes.');
            $code = $this->codes->generate($issuer, 1, (string) $locked->plan, $locked->duration_days, null, 'Telegram Store order '.$locked->reference())['codes'][0];

            $referrer = null;
            $bonus = 0;
            $customer = $locked->customer_id ? TelegramStoreCustomer::query()->find($locked->customer_id) : null;
            if ($customer?->referrer_id && $locked->amount_due_rub > 0) {
                // Only money paid in earns a bonus, so balance cannot be cycled between accounts.
                $bonus = intdiv($locked->amount_due_rub * $this->settings->referralPercent(), 100);
                if ($bonus > 0) {
                    $this->ledger->change($customer->referrer_id, $bonus, BalanceLedger::REFERRAL_BONUS, $locked->id);
                    $referrer = TelegramStoreCustomer::query()->find($customer->referrer_id);
                }
            }

            $locked->update([
                'status' => TelegramStoreOrder::PAID,
                'paid_at' => now(),
                'payment_provider' => $provider,
                'payment_reference' => $reference ?? $locked->payment_reference,
                'reviewed_by' => $adminTelegramId,
                'referral_bonus_rub' => $bonus,
                'activation_code' => Crypt::encryptString($code),
            ]);

            return new CompletedOrder($locked, $code, $referrer, $bonus);
        });
        $order->refresh();

        return $result;
    }

    public function activationCode(TelegramStoreOrder $order): ?string
    {
        return $order->status === TelegramStoreOrder::PAID && $order->activation_code
            ? Crypt::decryptString($order->activation_code)
            : null;
    }
}
