<?php

namespace App\Services\Operations;

use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Raises an operational alert (FULL_PLAN §14): the `alerts` log channel
 * (file, plus Slack when configured) and an audit event. The same alert is
 * raised at most once per hour.
 */
class Alerts
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $context  Never put secrets or UDIDs here.
     */
    public function raise(string $key, string $message, array $context = [], string $level = 'warning'): bool
    {
        if (! Cache::add('alert-raised:'.$key.':'.now()->format('YmdH'), true, now()->addHour())) {
            return false;
        }

        Log::channel('alerts')->log($level, $message, ['alert' => $key] + $context);
        $this->audit->record('alert.raised', after: ['alert' => $key, 'message' => $message] + $context, actor: Actor::system('alerts'));

        return true;
    }
}
