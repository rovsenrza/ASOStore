<?php

namespace App\Console\Commands;

use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\Bot\CustomerScreens;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\OrderService;
use Illuminate\Console\Command;

/**
 * Tells a buyer, once, that the code they paid for a day or more ago is still unused, with one
 * tap to put its days on their account. Most such codes were bought by someone who meant to
 * extend and never got round to typing the code in.
 */
class RemindUnusedCodesCommand extends Command
{
    protected $signature = 'store:remind-unused-codes {--dry-run : List the orders, send nothing} {--limit=40 : Messages per run} {--after-hours=24 : How long a code may lie before the reminder} {--max-days=45 : Older orders are left alone}';

    protected $description = 'Remind bot buyers once about a paid activation code nobody has redeemed';

    public function handle(OrderService $orders, CustomerScreens $screens, Messenger $messenger): int
    {
        $candidates = TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PAID)
            ->whereNotNull('customer_id')->whereNotNull('chat_id')->whereNull('renew_user_id')
            ->whereNull('code_reminded_at')
            ->whereBetween('paid_at', [now()->subDays((int) $this->option('max-days')), now()->subHours((int) $this->option('after-hours'))])
            ->oldest('paid_at')->get();

        $sent = 0;
        foreach ($candidates->groupBy('customer_id') as $customerId => $theirs) {
            $customer = TelegramStoreCustomer::query()->find($customerId);
            if ($customer === null) {
                continue;
            }
            $unused = $orders->unusedCodes($customer)->keyBy('id');
            foreach ($theirs as $order) {
                if (! $unused->has($order->id)) {
                    // Redeemed, revoked or expired meanwhile: nothing to say, and never look again.
                    $this->option('dry-run') || $order->forceFill(['code_reminded_at' => now()])->save();

                    continue;
                }
                if ($sent >= (int) $this->option('limit')) {
                    break 2;
                }
                $this->line(sprintf('%s order #%s · %s · paid %s · tg %d%s', $this->option('dry-run') ? 'WOULD' : 'SEND', $order->shortReference(), $order->plan_key, $order->paid_at?->toDateString(), $customer->telegram_user_id, $customer->blocked_at ? ' (blocked the bot)' : ''));
                $sent++;
                if ($this->option('dry-run')) {
                    continue;
                }
                // Marked first: a crash or a blocked chat must not make this a daily message.
                $order->forceFill(['code_reminded_at' => now()])->save();
                if ($customer->blocked_at === null) {
                    $messenger->notify((int) $order->chat_id, $screens->unusedCode($customer, $order, reminder: true));
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'DRY-RUN ' : 'SENT ').$sent);

        return self::SUCCESS;
    }
}
