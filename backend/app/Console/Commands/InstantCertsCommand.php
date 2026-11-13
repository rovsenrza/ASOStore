<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InstantCerts\InstantCertificates;
use App\Services\InstantCerts\NekooClient;
use Illuminate\Console\Command;

/**
 * Instant certificates (second registration method):
 *   instant-certs:manage status
 *   instant-certs:manage on  user@example.com     the customer's devices register by instant certificate
 *   instant-certs:manage off user@example.com     back to our Apple teams
 *   instant-certs:manage provision user@example.com [--key=KEY]   do it now for each of their devices
 */
class InstantCertsCommand extends Command
{
    protected $signature = 'instant-certs:manage {action : status|on|off|provision} {email?} {--key= : a prepaid reseller key to use instead of buying}';

    protected $description = 'Instant (reseller) certificates: switch customers over and provision their devices';

    public function handle(InstantCertificates $instant, NekooClient $api): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'status') {
            $me = $api->me();
            $this->line('enabled='.json_encode($instant->enabled()).' balance='.json_encode($me['balance'] ?? null).' role='.($me['role'] ?? '?'));
            $this->line('instant devices: '.\App\Models\Device::query()->where('registration_method', InstantCertificates::METHOD)->count());

            return self::SUCCESS;
        }
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No such customer.');

            return self::FAILURE;
        }
        if ($action === 'on' || $action === 'off') {
            // The method is per device; the account flag is kept in step for the older reports.
            $method = $action === 'on' ? InstantCertificates::METHOD : null;
            $user->forceFill(['registration_method' => $method])->save();
            $user->devices()->update(['registration_method' => $method]);
            $this->info("{$user->email}: every device on ".($action === 'on' ? 'instant certificate' : 'our Apple teams'));

            return self::SUCCESS;
        }
        if ($action === 'provision') {
            foreach ($user->devices()->whereNotNull('enrolled_at')->get() as $device) {
                $device->setRelation('user', $user);
                if (! $instant->wantedFor($device)) {
                    $this->warn("device {$device->id}: not an instant customer (or the feature is off)");

                    continue;
                }
                $registration = $instant->provision($device, $this->option('key') ?: null);
                $this->info("device {$device->id}: ".($registration === null ? 'busy, try again' : "registration {$registration->id} {$registration->status->value} on team {$registration->team->apple_team_id}"));
            }

            return self::SUCCESS;
        }
        $this->error('Unknown action.');

        return self::FAILURE;
    }
}
