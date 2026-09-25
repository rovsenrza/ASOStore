<?php

namespace App\Console\Commands;

use App\Enums\RoleSlug;
use App\Models\User;
use App\Services\Activation\ActivationCodeService;
use Illuminate\Console\Command;

/**
 * Issues activation codes from the command line (same rules and audit trail
 * as the admin panel). Codes are printed once, one per line.
 */
class IssueActivationCodes extends Command
{
    protected $signature = 'activation:issue
        {--count=1 : How many codes}
        {--days= : Subscription length in days (empty: no end date)}
        {--plan=standard : Plan name}
        {--note= : Note shown in the admin panel}
        {--by= : Email of the admin issuing them (default: the first admin)}';

    protected $description = 'Issue activation codes and print them once';

    public function handle(ActivationCodeService $codes): int
    {
        $issuer = $this->option('by')
            ? User::query()->where('email', $this->option('by'))->first()
            : User::query()->whereHas('roles', fn ($roles) => $roles->where('slug', RoleSlug::Admin->value))->oldest('id')->first();

        if ($issuer === null || ! $issuer->hasRole(RoleSlug::Admin)) {
            $this->error('No admin account to issue the codes as. Pass --by=<admin email>.');

            return self::FAILURE;
        }

        $count = max(1, min((int) $this->option('count'), (int) config('storefront.activation.max_batch')));
        $batch = $codes->generate($issuer, $count, (string) $this->option('plan'), $this->option('days') ? (int) $this->option('days') : null, null, $this->option('note'));

        foreach ($batch['codes'] as $code) {
            $this->line($code);
        }

        return self::SUCCESS;
    }
}
