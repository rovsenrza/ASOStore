<?php

namespace App\Services\Quotas;

use App\Enums\AppleTeamStatus;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\TeamQuota;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ReconcileQuotaJob (IMPLEMENTATION_PLAN P7-BE-04): compares local counters
 * with Apple's device list and raises alerts. It never corrects anything on
 * its own; a mismatch is for an operator to investigate.
 */
class QuotaReconciler
{
    public const WARN_DAYS = 30;

    public function __construct(
        private readonly AppleIntegration $apple,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{teams: int, mismatches: int, alerts: int}
     */
    public function run(?AppleTeam $only = null): array
    {
        $summary = ['teams' => 0, 'mismatches' => 0, 'alerts' => 0];
        $actor = Actor::system('reconcile');

        $teams = $only ? collect([$only]) : AppleTeam::query()
            ->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value])
            ->get();

        foreach ($teams as $team) {
            $summary['alerts'] += $this->membershipAlerts($team, $actor);

            $year = $team->currentMembershipYear();
            if ($year === null || ! $this->apple->isConfigured($team)) {
                continue;
            }

            try {
                $apple = $this->apple->countDevicesByFamily($team);
            } catch (AppleException $e) {
                Log::warning('quota.reconcile_failed', ['team' => $team->apple_team_id, 'reason' => $e->reason]);

                continue;
            }
            $summary['teams']++;

            foreach (['IPHONE', 'IPAD', 'IPOD'] as $family) {
                $quota = TeamQuota::query()->firstOrCreate(
                    ['apple_team_id' => $team->id, 'membership_year_id' => $year->id, 'device_family' => $family],
                    ['limit_count' => (int) config('storefront.apple.device_limit_per_family')],
                );
                $appleCount = (int) ($apple[$family] ?? 0);
                $local = $quota->registeredCount();
                $quota->forceFill(['apple_registered_count' => $appleCount, 'last_synced_at' => now()])->save();

                if ($appleCount !== $local) {
                    $summary['mismatches']++;
                    // Devices added in the Apple portal by hand also use slots: flag, do not correct.
                    $this->audit->record('quota.mismatch', $team, after: ['family' => $family, 'local' => $local, 'apple' => $appleCount], actor: $actor);
                    Log::channel('alerts')->alert('quota.mismatch', ['team' => $team->apple_team_id, 'family' => $family, 'local' => $local, 'apple' => $appleCount]);
                }
            }
        }

        $summary['alerts'] += $this->certificateAlerts($actor);

        return $summary;
    }

    private function membershipAlerts(AppleTeam $team, Actor $actor): int
    {
        $expires = $team->membership_expires_at;
        if ($expires === null || $expires->gt(now()->addDays(self::WARN_DAYS))) {
            return 0;
        }

        if ($team->status === AppleTeamStatus::Active) {
            $team->forceFill(['status' => AppleTeamStatus::Expiring])->save();
        }

        return $this->alertOnce("team:{$team->id}", function () use ($team, $expires, $actor) {
            $this->audit->record('team.membership_expiring', $team, after: ['expires_at' => $expires->toIso8601ZuluString()], actor: $actor);
            Log::channel('alerts')->alert('team.membership_expiring', ['team' => $team->apple_team_id, 'expires_at' => $expires->toIso8601ZuluString()]);
        });
    }

    private function certificateAlerts(Actor $actor): int
    {
        $alerts = 0;
        $expiring = Certificate::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->addDays(self::WARN_DAYS))
            ->get();

        foreach ($expiring as $certificate) {
            $alerts += $this->alertOnce("certificate:{$certificate->id}", function () use ($certificate, $actor) {
                $this->audit->record('certificate.expiring', $certificate, after: [
                    'sha1' => $certificate->sha1_fingerprint,
                    'expires_at' => $certificate->expires_at?->toIso8601ZuluString(),
                ], actor: $actor);
                Log::channel('alerts')->alert('certificate.expiring', ['sha1' => $certificate->sha1_fingerprint, 'expires_at' => $certificate->expires_at?->toIso8601ZuluString()]);
            });
        }

        return $alerts;
    }

    /**
     * One alert per subject per day, however often reconciliation runs.
     */
    private function alertOnce(string $key, callable $raise): int
    {
        if (! Cache::add("alert:{$key}:".now()->toDateString(), true, now()->addDay())) {
            return 0;
        }
        $raise();

        return 1;
    }
}
