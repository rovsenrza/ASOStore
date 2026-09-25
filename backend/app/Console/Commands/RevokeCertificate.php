<?php

namespace App\Console\Commands;

use App\Enums\SignedBuildStatus;
use App\Models\Certificate;
use App\Models\SignedBuild;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\StateMachines\StateMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marks a signing certificate revoked and revokes every build signed with it,
 * so the next install request signs a fresh build with a valid identity
 * (docs/runbooks/credential-rotation.md).
 */
class RevokeCertificate extends Command
{
    protected $signature = 'certificate:revoke {sha1 : SHA-1 fingerprint} {--reason= : Why (required)}';

    protected $description = 'Revoke a signing certificate and the builds signed with it';

    public function handle(AuditService $audit, StateMachine $states): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required.');

            return self::FAILURE;
        }

        $certificate = Certificate::query()->where('sha1_fingerprint', strtoupper((string) $this->argument('sha1')))->first();
        if ($certificate === null) {
            $this->error('No certificate with that fingerprint.');

            return self::FAILURE;
        }

        $count = DB::transaction(function () use ($certificate, $reason, $audit, $states) {
            $actor = Actor::system('console');
            $certificate->forceFill(['status' => 'REVOKED'])->save();
            $audit->record('certificate.revoked', $certificate, reason: $reason, actor: $actor);

            $builds = SignedBuild::query()->where('certificate_id', $certificate->id)->where('status', SignedBuildStatus::Deliverable->value)->get();
            foreach ($builds as $build) {
                $states->transition($build, SignedBuildStatus::Revoked, $reason, $actor, extra: ['status_reason' => 'CERTIFICATE_REVOKED']);
            }

            return $builds->count();
        });

        $this->info("Certificate revoked; {$count} signed builds revoked. New installs will be signed with another valid identity.");

        return self::SUCCESS;
    }
}
