<?php

namespace App\Services\Operations;

use App\Enums\AppleTeamStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\SignedBuildStatus;
use App\Models\AppleTeam;
use App\Models\InstallationEvent;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Models\SignedBuild;
use App\Services\Quotas\QuotaService;
use App\Services\Signing\SigningService;
use Illuminate\Support\Facades\DB;

/**
 * Checks the FULL_PLAN §14 alert conditions (IMPLEMENTATION_PLAN P8-OPS-02,
 * P8-SEC-02). Certificate, profile and membership expiry are raised by
 * QuotaReconciler. Returns the keys of alerts raised in this run.
 */
class AlertEvaluator
{
    public function __construct(
        private readonly Alerts $alerts,
        private readonly QuotaService $quotas,
    ) {}

    /**
     * @return list<string>
     */
    public function run(): array
    {
        $limits = config('storefront.alerts');
        $raised = [];
        $raise = function (string $key, string $message, array $context = [], string $level = 'warning') use (&$raised) {
            if ($this->alerts->raise($key, $message, $context, $level)) {
                $raised[] = $key;
            }
        };

        // Apple credentials invalid.
        foreach (AppleTeam::query()->where('status', AppleTeamStatus::Disconnected->value)->get() as $team) {
            $raise("apple-credentials:{$team->apple_team_id}", 'Apple credentials are invalid or disconnected.', ['team' => $team->apple_team_id], 'error');
        }

        // Quota below threshold.
        foreach (AppleTeam::query()->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value])->get() as $team) {
            foreach ($this->quotas->summary($team) as $quota) {
                if ($quota['limit'] > 0 && $limits['quota_remaining_ratio'] >= $quota['remaining'] / $quota['limit']) {
                    $raise("quota-low:{$team->apple_team_id}:{$quota['family']}", 'Device slots are running out.', ['team' => $team->apple_team_id, 'family' => $quota['family'], 'remaining' => $quota['remaining']]);
                }
            }
        }

        // Repeated signature failures.
        $failures = SignedBuild::query()->where('updated_at', '>=', now()->subHour())
            ->whereIn('status', [SignedBuildStatus::SigningFailed->value, SignedBuildStatus::ValidationFailed->value])->count();
        if ($failures >= $limits['signing_failures_per_hour']) {
            $raise('signing-failures', 'Repeated signing failures in the last hour.', ['count' => $failures], 'error');
        }

        // Queue backlog.
        $backlog = PipelineJob::query()->where('status', PipelineJobStatus::Queued->value)->count() + DB::table('jobs')->count();
        if ($backlog >= $limits['queue_backlog']) {
            $raise('queue-backlog', 'Queue backlog above threshold.', ['queued' => $backlog]);
        }

        // Signing runner offline while work waits.
        $waiting = PipelineJob::query()->where('type', SigningService::RUNNER_JOB_TYPE)->where('status', PipelineJobStatus::Queued->value)->exists();
        $online = Runner::query()->where('status', 'ACTIVE')->where('last_heartbeat_at', '>', now()->subMinutes($limits['runner_offline_minutes']))->exists();
        if ($waiting && ! $online) {
            $raise('runner-offline', 'No signing runner is online and signing jobs are waiting.', [], 'error');
        }

        // Storage capacity risk.
        $root = storage_path('app');
        $total = @disk_total_space($root) ?: 0;
        $free = @disk_free_space($root) ?: 0;
        if ($total > 0 && $free / $total < $limits['storage_free_ratio']) {
            $raise('storage-capacity', 'Artifact storage is almost full.', ['free_bytes' => (int) $free, 'total_bytes' => (int) $total], 'error');
        }

        // Unusual download spikes per user, and enrollment spikes per IP.
        $downloads = InstallationEvent::query()
            ->join('installations', 'installations.id', '=', 'installation_events.installation_id')
            ->where('installation_events.type', 'DOWNLOAD_STARTED')
            ->where('installation_events.created_at', '>=', now()->subHour())
            ->groupBy('installations.user_id')
            ->havingRaw('count(*) >= ?', [$limits['downloads_per_user_per_hour']])
            ->pluck('installations.user_id');
        foreach ($downloads as $userId) {
            $raise("download-spike:user:{$userId}", 'Unusual number of downloads by one user.', ['user_id' => $userId]);
        }

        $enrollments = DB::table('enrollment_challenges')
            ->whereNotNull('used_at')->where('used_at', '>=', now()->subHour())->whereNotNull('ip')
            ->groupBy('ip')->havingRaw('count(*) >= ?', [$limits['enrollments_per_ip_per_hour']])
            ->pluck('ip');
        foreach ($enrollments as $ip) {
            $raise('enrollment-spike:'.hash('sha256', (string) $ip), 'Unusual number of device registrations from one IP address.', ['ip' => $ip]);
        }

        // Backup freshness and verification (written by scripts/backup.sh).
        $status = $this->backupStatus($limits['backup_status_file']);
        if ($status === null || ! ($status['verified'] ?? false) || strtotime((string) ($status['finished_at'] ?? '')) < now()->subHours($limits['backup_max_age_hours'])->getTimestamp()) {
            $raise('backup', 'The latest backup is missing, too old or failed verification.', ['status' => $status], 'error');
        }

        return $raised;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backupStatus(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }
}
