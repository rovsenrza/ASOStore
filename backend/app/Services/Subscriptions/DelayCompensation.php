<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\DeviceRegistration;
use App\Models\Subscription;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Makes up for a slow device registration. When a customer waits longer than the threshold
 * (config storefront.delay_compensation) for Apple to enable their device (or for a team with a
 * free slot), their subscription gets
 *   - the gift (default 7 days), as soon as the wait passes the threshold, and
 *   - the time they actually waited, once the device is enabled.
 * Each registration is handled once: delay_gift_at and delay_assessed_at remember what was given,
 * so the scheduled run (every few minutes) can be repeated safely.
 */
class DelayCompensation
{
    /** Registrations that have not been enabled yet but are still expected to be. */
    private const WAITING = ['ENROLLED', 'APPLE_PENDING', 'QUOTA_BLOCKED', 'NO_ELIGIBLE_TEAM'];

    private const BATCH = 500;

    public function __construct(private readonly AuditService $audit) {}

    public function enabled(): bool
    {
        return (bool) config('storefront.delay_compensation.enabled');
    }

    public function thresholdMinutes(): int
    {
        return max(1, (int) config('storefront.delay_compensation.threshold_minutes'));
    }

    public function giftDays(): int
    {
        return max(0, (int) config('storefront.delay_compensation.gift_days'));
    }

    /**
     * @return array{gifts: int, waits: int, assessed: int, skipped: int, gift_days: int, wait_minutes: int}
     */
    public function run(bool $dryRun = false, ?Carbon $now = null): array
    {
        $stats = ['gifts' => 0, 'waits' => 0, 'assessed' => 0, 'skipped' => 0, 'gift_days' => 0, 'wait_minutes' => 0];
        if (! $this->enabled()) {
            return $stats;
        }
        $now ??= now();

        $ids = DeviceRegistration::query()
            ->where(fn ($query) => $query
                // Enabled by Apple, wait not assessed yet.
                ->where(fn ($enabled) => $enabled->whereNotNull('eligible_at')->whereNull('delay_assessed_at'))
                // Still waiting for longer than the threshold, gift not given yet.
                ->orWhere(fn ($waiting) => $waiting->whereNull('eligible_at')->whereNull('delay_gift_at')
                    ->whereIn('status', self::WAITING)->where('created_at', '<=', $now->copy()->subMinutes($this->thresholdMinutes()))))
            ->orderBy('id')->limit(self::BATCH)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, $dryRun, &$stats) {
                $this->handle((int) $id, $now, $dryRun, $stats);
            });
        }

        return $stats;
    }

    /** @param array<string, int> $stats */
    private function handle(int $id, Carbon $now, bool $dryRun, array &$stats): void
    {
        $registration = DeviceRegistration::query()->whereKey($id)->lockForUpdate()->first();
        if ($registration === null) {
            return;
        }
        $enabled = $registration->eligible_at !== null;
        if ($enabled && $registration->delay_assessed_at !== null) {
            return;
        }

        $waited = intdiv(max(0, ($enabled ? $registration->eligible_at : $now)->getTimestamp() - $registration->created_at->getTimestamp()), 60);
        $delayed = $waited > $this->thresholdMinutes();
        $giftDays = $delayed && $registration->delay_gift_at === null ? $this->giftDays() : 0;
        $waitMinutes = $enabled && $delayed ? $waited : 0;

        if ($giftDays === 0 && $waitMinutes === 0) {
            // Enabled in time (or already paid for): nothing is owed, do not look at it again.
            if ($enabled && ! $dryRun) {
                $registration->forceFill(['delay_assessed_at' => $now, 'delay_compensation_minutes' => 0])->save();
            }
            $stats['assessed'] += $enabled ? 1 : 0;

            return;
        }

        $subscription = Subscription::query()
            ->where('user_id', $registration->device?->user_id)
            ->where('status', SubscriptionStatus::Active->value)->whereNotNull('ends_at')
            ->orderByDesc('ends_at')->lockForUpdate()->first();
        if ($subscription === null) {
            $stats['skipped']++;

            return;
        }

        $stats['gifts'] += $giftDays > 0 ? 1 : 0;
        $stats['waits'] += $waitMinutes > 0 ? 1 : 0;
        $stats['gift_days'] += $giftDays;
        $stats['wait_minutes'] += $waitMinutes;
        if ($dryRun) {
            return;
        }

        $before = $subscription->ends_at->copy();
        $subscription->forceFill(['ends_at' => $before->copy()->addDays($giftDays)->addMinutes($waitMinutes)])->save();
        $registration->forceFill(array_filter([
            'delay_gift_at' => $giftDays > 0 ? $now : null,
            'delay_assessed_at' => $enabled ? $now : null,
            'delay_compensation_minutes' => $enabled ? $waitMinutes : null,
        ], fn ($value) => $value !== null))->save();

        $this->audit->record('subscription.extended', $subscription,
            before: ['ends_at' => $before->utc()->format('Y-m-d\TH:i:s\Z')],
            after: ['days' => $giftDays, 'delay_minutes' => $waitMinutes, 'ends_at' => $subscription->ends_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')],
            reason: 'Compensation for the Apple device registration delay: '.implode(' + ', array_filter([
                $giftDays > 0 ? "{$giftDays} days gift" : null,
                $waitMinutes > 0 ? "{$waitMinutes} minutes waited" : null,
            ])).'.',
            actor: Actor::system('delay-compensation'));
    }
}
