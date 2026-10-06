<?php

namespace App\Services\TelegramStore\Payments;

use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\Bot\Format;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use App\Services\TelegramStore\CompletedOrder;
use App\Services\TelegramStore\OrderFulfillment;
use App\Services\TelegramStore\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Store orders paid through Platega, from the bot or the website. Platega's word on a
 * transaction is always fetched from its API: a callback, the buyer's «check» button and
 * the reconcile sweep only say when to ask.
 */
class PlategaPayments
{
    public const PROVIDER = 'platega';

    public const METHOD = 'online';

    public function __construct(
        private readonly PlategaClient $client,
        private readonly OrderService $orders,
        private readonly OrderFulfillment $fulfillment,
        private readonly Messenger $messenger,
    ) {}

    /**
     * The order's payment page: the open one while it is valid for the current amount,
     * otherwise a new transaction. Throws PlategaException when Platega cannot be reached.
     */
    public function paymentUrl(TelegramStoreOrder $order, ?string $clientIp = null): string
    {
        if ($order->payment_provider === self::PROVIDER && $order->payment_url && $order->payment_reference
            && $order->payment_amount_rub === $order->amount_due_rub && $order->payment_expires_at?->isFuture()) {
            return $order->payment_url;
        }

        $page = url('/payment.html?'.http_build_query(['order' => $order->public_id]));
        $created = $this->client->create([
            'paymentDetails' => ['amount' => $order->amount_due_rub, 'currency' => 'RUB'],
            'description' => $this->description($order),
            'return' => $page,
            'failedUrl' => $page.'&failed=1',
            'payload' => $order->public_id,
            'orderId' => $order->reference(),
            'metadata' => $this->payer($order, $clientIp),
        ]);

        $order->update([
            'payment_method' => self::METHOD,
            'payment_provider' => self::PROVIDER,
            'payment_reference' => $created['id'],
            'payment_url' => $created['url'],
            'payment_amount_rub' => $order->amount_due_rub,
            // A minute short of Platega's own expiry, so a link is never handed out about to die.
            'payment_expires_at' => now()->addSeconds(max(60, $created['expires_in'] - 60)),
        ]);

        return $created['url'];
    }

    /**
     * Applies a transaction's current state to its order. Returns the completed order when
     * this call paid it. Throws PlategaException when Platega cannot be asked, so a callback
     * answers with an error and Platega retries.
     */
    public function settle(string $transactionId): ?CompletedOrder
    {
        $transaction = $this->client->transaction($transactionId);
        $order = TelegramStoreOrder::query()->where('payment_reference', $transactionId)->first()
            ?? ($transaction['payload'] !== null ? $this->orders->find($transaction['payload']) : null);
        if ($order === null) {
            Log::warning('Platega transaction without a store order.', ['transaction' => $transactionId, 'status' => $transaction['status']]);

            return null;
        }

        return match ($transaction['status']) {
            'CONFIRMED' => $this->confirmed($order, $transactionId, $transaction),
            'CANCELED' => $this->cancelled($order, $transactionId),
            'CHARGEBACKED' => $this->chargedBack($order, $transactionId, $transaction),
            default => null,
        };
    }

    /** Settles the order's open transaction; null when nothing changed or Platega is down. */
    public function check(TelegramStoreOrder $order): ?CompletedOrder
    {
        if ($order->payment_provider !== self::PROVIDER || ! $order->payment_reference || $order->status === TelegramStoreOrder::PAID) {
            return null;
        }
        try {
            return $this->settle($order->payment_reference);
        } catch (PlategaException $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Catches payments whose callback never came: open Platega orders of the last
     * hours are asked again. Runs from the scheduler.
     */
    public function reconcile(): int
    {
        if (! PlategaClient::configured()) {
            return 0;
        }

        $settled = 0;
        TelegramStoreOrder::query()
            ->where('payment_provider', self::PROVIDER)
            ->whereNotNull('payment_reference')
            ->whereIn('status', [TelegramStoreOrder::PENDING, TelegramStoreOrder::EXPIRED, TelegramStoreOrder::CANCELLED])
            ->where('created_at', '>=', now()->subHours(3))
            ->oldest('id')
            ->limit(50)
            ->get()
            ->each(function (TelegramStoreOrder $order) use (&$settled) {
                try {
                    $settled += $this->settle((string) $order->payment_reference) !== null ? 1 : 0;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $settled;
    }

    /**
     * @param  array{status: string, amount: float, currency: string, payload: string|null}  $transaction
     */
    private function confirmed(TelegramStoreOrder $order, string $transactionId, array $transaction): ?CompletedOrder
    {
        if ($order->status === TelegramStoreOrder::PAID && $order->payment_reference === $transactionId) {
            return null;
        }

        if ($transaction['currency'] !== 'RUB' || $order->amount_due_rub > $transaction['amount'] + 0.005) {
            $this->alertOnce($transactionId, 'mismatch', $order,
                '⚠️ <b>Сумма оплаты не совпала с заказом</b>',
                'Оплачено: '.e(rtrim(rtrim(number_format($transaction['amount'], 2, '.', ''), '0'), '.').' '.$transaction['currency'])
                ."\nСверьте платёж в Platega и подтвердите заказ вручную или верните деньги.");

            return null;
        }

        $completed = $this->orders->complete($order, self::PROVIDER, settled: true, reference: $transactionId);
        if ($completed !== null) {
            $this->fulfillment->deliver($completed);

            return $completed;
        }

        $order->refresh();
        if ($order->status === TelegramStoreOrder::PAID) {
            if ($order->payment_reference !== $transactionId) {
                $this->alertOnce($transactionId, 'duplicate', $order,
                    '⚠️ <b>Повторная оплата уже оплаченного заказа</b>',
                    'Покупатель заплатил дважды. Верните деньги в Platega или выдайте ещё один код.');
            }

            return null;
        }

        // Closed with balance on it: closing gave the balance back, so an admin decides.
        if ($this->orders->reopenForReview($order)) {
            $this->alertOnce($transactionId, 'closed', $order,
                '🔔 <b>Оплата пришла после закрытия заказа</b>',
                'Заказ был закрыт, а частично оплаченный балансом остаток вернулся покупателю. Подтвердите заказ или верните деньги.',
                withActions: true);
        }

        return null;
    }

    private function cancelled(TelegramStoreOrder $order, string $transactionId): null
    {
        // The page is dead: the next «pay» opens a new one.
        if ($order->status === TelegramStoreOrder::PENDING && $order->payment_reference === $transactionId) {
            $order->update(['payment_url' => null, 'payment_expires_at' => null]);
        }

        return null;
    }

    /**
     * @param  array{status: string, amount: float, currency: string, payload: string|null}  $transaction
     */
    private function chargedBack(TelegramStoreOrder $order, string $transactionId, array $transaction): null
    {
        if ($order->status === TelegramStoreOrder::PAID && $order->payment_reference === $transactionId) {
            $this->alertOnce($transactionId, 'chargeback', $order,
                '↩️ <b>Возврат платежа (chargeback)</b>',
                'Деньги по заказу вернулись покупателю. Доступ по коду этого заказа не отозван автоматически.');
        }

        return null;
    }

    private function alertOnce(string $transactionId, string $kind, TelegramStoreOrder $order, string $title, string $text, bool $withActions = false): void
    {
        if (! Cache::add("platega.alert.{$kind}.{$transactionId}", true, now()->addDays(7))) {
            return;
        }

        $buyer = $order->isWeb()
            ? 'сайт, '.e($order->user?->email ?? 'аккаунт удалён')
            : e($order->username ? '@'.$order->username : (string) $order->telegram_user_id);
        $rows = $withActions
            ? [[Screen::button('✅ Подтвердить оплату', 'adm:ok:'.$order->public_id), Screen::button('❌ Отклонить', 'adm:no:'.$order->public_id)]]
            : [];

        $this->messenger->notifyAdmins(new Screen(
            "{$title}\n\nЗаказ <code>{$order->reference()}</code> · ".Format::rub($order->amount_due_rub)
            ."\nПокупатель: {$buyer}\nТранзакция Platega: <code>".e($transactionId)."</code>\n\n{$text}",
            $rows,
        ));
    }

    private function description(TelegramStoreOrder $order): string
    {
        $brand = (string) config('telegram_store.brand', 'Ru App Store');

        return "{$brand}: доступ на ".Format::days($order->duration_days).', заказ #'.$order->shortReference();
    }

    /**
     * Who pays, for Platega's anti-fraud (required for some shop categories).
     *
     * @return array<string, string>
     */
    private function payer(TelegramStoreOrder $order, ?string $clientIp): array
    {
        $payer = $order->isWeb()
            ? ['userId' => 'web-'.($order->user?->public_id ?? $order->user_id), 'userName' => 'web-'.($order->user?->public_id ?? $order->user_id)]
            : ['userId' => (string) $order->telegram_user_id, 'userName' => $order->username ? '@'.$order->username : (string) $order->telegram_user_id];

        return $clientIp !== null ? $payer + ['clientIp' => $clientIp] : $payer;
    }
}
