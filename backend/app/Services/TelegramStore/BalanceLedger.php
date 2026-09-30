<?php

namespace App\Services\TelegramStore;

use App\Models\TelegramStoreCustomer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every balance change writes a ledger row in the same transaction as the
 * balance itself, so the balance always equals the sum of its ledger.
 */
class BalanceLedger
{
    public const REFERRAL_BONUS = 'REFERRAL_BONUS';

    public const ORDER_PAYMENT = 'ORDER_PAYMENT';

    public const ORDER_REFUND = 'ORDER_REFUND';

    public const ADMIN_ADJUSTMENT = 'ADMIN_ADJUSTMENT';

    /**
     * Applies a signed change and returns the new balance. A debit larger than
     * the balance fails.
     */
    public function change(int $customerId, int $amount, string $type, ?int $orderId = null, ?string $note = null): int
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('A balance change cannot be zero.');
        }

        return DB::transaction(function () use ($customerId, $amount, $type, $orderId, $note) {
            $customer = TelegramStoreCustomer::query()->lockForUpdate()->findOrFail($customerId);
            $balance = $customer->balance_rub + $amount;
            if ($balance < 0) {
                throw new InvalidArgumentException('Insufficient balance.');
            }

            $updates = ['balance_rub' => $balance, 'updated_at' => now()];
            if ($type === self::REFERRAL_BONUS) {
                $updates['referral_earned_rub'] = $customer->referral_earned_rub + $amount;
            }
            DB::table('telegram_store_customers')->where('id', $customerId)->update($updates);
            DB::table('telegram_store_balance_transactions')->insert([
                'customer_id' => $customerId,
                'amount_rub' => $amount,
                'type' => $type,
                'order_id' => $orderId,
                'note' => $note,
                'created_at' => now(),
            ]);

            return $balance;
        });
    }
}
