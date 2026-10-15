<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\DelayCompensation;
use Illuminate\Console\Command;

/**
 * Adds the gift and the waited time to the subscription of every customer whose device
 * registration took too long (see DelayCompensation). Scheduled every five minutes.
 */
class CompensateRegistrationDelays extends Command
{
    protected $signature = 'subscriptions:compensate-delays {--dry-run : Only count what would be added}';

    protected $description = 'Compensate customers whose Apple device registration took more than 30 minutes';

    public function handle(DelayCompensation $compensation): int
    {
        if (! $compensation->enabled()) {
            $this->warn('Delay compensation is switched off (STOREFRONT_DELAY_COMPENSATION_ENABLED).');

            return self::SUCCESS;
        }
        $stats = $compensation->run((bool) $this->option('dry-run'));
        $this->line(($this->option('dry-run') ? 'DRY RUN ' : '').json_encode($stats));

        return self::SUCCESS;
    }
}
