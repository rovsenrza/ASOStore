<?php

namespace App\Console\Commands;

use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\UpdateRouter;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\TelegramApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramStoreBot extends Command
{
    protected $signature = 'telegram:store-bot {--once : Process one polling batch and stop}';

    protected $description = 'Run the Ru App Store subscription bot using Telegram long polling';

    private const OFFSET_KEY = 'telegram_store.update_offset';

    private const SWEEP_SECONDS = 60;

    private bool $stopping = false;

    public function handle(TelegramApi $api, UpdateRouter $router, OrderService $orders, Messenger $messenger): int
    {
        if ((string) config('telegram_store.token') === '') {
            $this->error('Set TELEGRAM_STORE_BOT_TOKEN in the backend environment.');

            return self::FAILURE;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        try {
            $api->setCommands();
            $this->info('Bot @'.$api->botUsername().' started'.(GatewayResolver::mock() ? ' in MOCK payment mode.' : '.'));
        } catch (Throwable $exception) {
            report($exception);
        }

        $offset = (int) Cache::get(self::OFFSET_KEY, 0);
        $lastSweep = 0;
        do {
            if (time() - $lastSweep >= self::SWEEP_SECONDS) {
                $lastSweep = time();
                $this->sweep($orders, $messenger);
            }

            try {
                $updates = $api->getUpdates($offset, $this->option('once') ? 0 : 25);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn('Telegram polling failed; retrying shortly.');
                sleep(3);

                continue;
            }

            foreach ($updates as $update) {
                $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                // Saved before handling: a crashing update is skipped instead of replayed forever.
                Cache::forever(self::OFFSET_KEY, $offset);
                try {
                    $router->handle($update);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        } while (! $this->option('once') && ! $this->stopping);

        return self::SUCCESS;
    }

    private function sweep(OrderService $orders, Messenger $messenger): void
    {
        try {
            $orders->expireStale()->each(fn ($order) => $messenger->orderExpired($order));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
