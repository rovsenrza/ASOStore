<?php

namespace App\Jobs;

use App\Models\TelegramStoreBroadcast;
use App\Models\TelegramStoreCustomer;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use App\Services\TelegramStore\TelegramApi;
use App\Services\TelegramStore\TelegramApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Copies the admin's message to every customer who has not blocked the bot.
 * Works in short slices (well under the queue's retry_after) and re-dispatches
 * itself, resuming from the stored cursor.
 */
class TelegramStoreBroadcastJob implements ShouldQueue
{
    use Queueable;

    private const SLICE_SECONDS = 40;

    // Telegram allows about 30 messages per second across chats.
    private const PAUSE_MICROSECONDS = 40_000;

    public int $tries = 1;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(TelegramApi $api, Messenger $messenger): void
    {
        $broadcast = TelegramStoreBroadcast::query()->find($this->broadcastId);
        if (! $broadcast || $broadcast->status === 'DONE') {
            return;
        }
        $broadcast->update(['status' => 'RUNNING']);
        $deadline = microtime(true) + self::SLICE_SECONDS;

        while (microtime(true) < $deadline) {
            $customers = TelegramStoreCustomer::query()
                ->whereNull('blocked_at')
                ->where('id', '>', $broadcast->cursor_id)
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'chat_id']);
            if ($customers->isEmpty()) {
                $broadcast->update(['status' => 'DONE', 'finished_at' => now()]);
                $messenger->notify($broadcast->admin_chat_id, new Screen(
                    "📣 <b>Рассылка завершена</b>\n\nДоставлено: {$broadcast->sent}\nНе доставлено: {$broadcast->failed}",
                ));

                return;
            }

            foreach ($customers as $customer) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $delivered = $this->deliver($api, $broadcast, $customer);
                $broadcast->cursor_id = $customer->id;
                $delivered ? $broadcast->sent++ : $broadcast->failed++;
                $broadcast->save();
                usleep(self::PAUSE_MICROSECONDS);
            }
        }

        self::dispatch($this->broadcastId);
    }

    private function deliver(TelegramApi $api, TelegramStoreBroadcast $broadcast, TelegramStoreCustomer $customer): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $api->copyMessage($customer->chat_id, $broadcast->from_chat_id, $broadcast->message_id);

                return true;
            } catch (TelegramApiException $exception) {
                if ($exception->isUnreachable()) {
                    $customer->forceFill(['blocked_at' => now()])->save();

                    return false;
                }
                if ($exception->getCode() !== 429) {
                    return false;
                }
                sleep(3);
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        }

        return false;
    }
}
