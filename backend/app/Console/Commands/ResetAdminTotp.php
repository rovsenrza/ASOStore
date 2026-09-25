<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Break-glass reset of a staff member's authenticator, for when no other
 * admin can do it from the panel. Requires server access; audited.
 */
class ResetAdminTotp extends Command
{
    protected $signature = 'admin:reset-totp {email} {--reason= : Why the reset is needed}';

    protected $description = 'Reset a staff member\'s two-factor authentication';

    public function handle(AuditService $audit): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $reason = (string) ($this->option('reason') ?: 'Reset from the command line');
        DB::transaction(function () use ($user, $audit, $reason) {
            $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $audit->record('user.totp_reset', $user, reason: $reason, actor: Actor::system('cli'));
        });

        $this->info("{$user->email} will enrol a new authenticator at the next admin sign-in.");

        return self::SUCCESS;
    }
}
