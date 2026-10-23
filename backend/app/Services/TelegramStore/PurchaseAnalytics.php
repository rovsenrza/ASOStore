<?php

namespace App\Services\TelegramStore;

use App\Models\TelegramStoreOrder;
use Illuminate\Support\Carbon;

/**
 * Repeat-purchase analytics over paid store orders (bot + website). A "buyer" is one
 * Telegram user (bot) or one site user (website); a repeat buyer has >= 2 paid orders.
 * Mock/test payments are excluded so the numbers reflect real sales.
 */
class PurchaseAnalytics
{
    /**
     * @return array{
     *   range: array{from: ?string, to: string},
     *   paid_orders: int, revenue_rub: int,
     *   unique_buyers: int, repeat_buyers: int, repeat_buyer_rate: float,
     *   first_orders: int, first_revenue_rub: int,
     *   repeat_orders: int, repeat_revenue_rub: int, repeat_revenue_rate: float,
     *   by_channel: array<string, array{paid_orders: int, revenue_rub: int, unique_buyers: int, repeat_buyers: int}>
     * }
     */
    public function summary(?int $days = null): array
    {
        $from = $days !== null ? now()->subDays($days)->startOfDay() : null;

        $query = TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PAID)
            ->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock'));
        if ($from !== null) {
            $query->where('paid_at', '>=', $from);
        }

        $orders = $query->orderBy('paid_at')
            ->get(['user_id', 'telegram_user_id', 'amount_due_rub', 'paid_at']);

        // channel is not a column — derive it like present()/isWeb(): a website order has a user_id.
        $channel = fn ($o) => $o->user_id !== null ? 'web' : 'telegram';

        // Group each paid order under its buyer, oldest first, keeping the amount.
        $buyers = [];            // buyerKey => list<int amount>
        foreach ($orders as $o) {
            $key = $o->user_id !== null ? 'u'.$o->user_id : 't'.$o->telegram_user_id;
            $buyers[$key][] = (int) $o->amount_due_rub;
        }

        $paidOrders = $orders->count();
        $revenue = (int) $orders->sum('amount_due_rub');
        $uniqueBuyers = count($buyers);
        $repeatBuyers = 0;
        $firstRevenue = 0;
        $repeatRevenue = 0;
        foreach ($buyers as $amounts) {
            $firstRevenue += $amounts[0];
            if (count($amounts) >= 2) {
                $repeatBuyers++;
                $repeatRevenue += array_sum(array_slice($amounts, 1));
            }
        }
        $firstOrders = $uniqueBuyers;                 // one first order per buyer
        $repeatOrders = $paidOrders - $uniqueBuyers;  // every order past a buyer's first

        $byChannel = [];
        foreach ($orders as $o) {
            $ch = $channel($o);
            $byChannel[$ch] ??= ['paid_orders' => 0, 'revenue_rub' => 0, 'buyers' => []];
            $byChannel[$ch]['paid_orders']++;
            $byChannel[$ch]['revenue_rub'] += (int) $o->amount_due_rub;
            $k = $o->user_id !== null ? 'u'.$o->user_id : 't'.$o->telegram_user_id;
            $byChannel[$ch]['buyers'][$k] = ($byChannel[$ch]['buyers'][$k] ?? 0) + 1;
        }
        $channels = [];
        foreach ($byChannel as $ch => $row) {
            $channels[$ch] = [
                'paid_orders' => $row['paid_orders'],
                'revenue_rub' => $row['revenue_rub'],
                'unique_buyers' => count($row['buyers']),
                'repeat_buyers' => count(array_filter($row['buyers'], fn ($n) => $n >= 2)),
            ];
        }

        return [
            'range' => ['from' => $from?->toDateString(), 'to' => Carbon::now()->toDateString()],
            'paid_orders' => $paidOrders,
            'revenue_rub' => $revenue,
            'unique_buyers' => $uniqueBuyers,
            'repeat_buyers' => $repeatBuyers,
            'repeat_buyer_rate' => $uniqueBuyers > 0 ? round($repeatBuyers / $uniqueBuyers * 100, 1) : 0.0,
            'first_orders' => $firstOrders,
            'first_revenue_rub' => $firstRevenue,
            'repeat_orders' => $repeatOrders,
            'repeat_revenue_rub' => $repeatRevenue,
            'repeat_revenue_rate' => $revenue > 0 ? round($repeatRevenue / $revenue * 100, 1) : 0.0,
            'by_channel' => $channels,
        ];
    }

    /**
     * Whether this buyer already had a paid order before the given one — used to tag the
     * Yandex.Metrika purchase goal as first vs repeat on the payment-success page.
     */
    public function isRepeatBuyer(TelegramStoreOrder $order): bool
    {
        $query = TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PAID)
            ->where('id', '!=', $order->id)
            ->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock'));

        if ($order->user_id !== null) {
            $query->where('user_id', $order->user_id);
        } elseif ($order->telegram_user_id !== null) {
            $query->where('telegram_user_id', $order->telegram_user_id);
        } else {
            return false;
        }

        return $query->exists();
    }
}
