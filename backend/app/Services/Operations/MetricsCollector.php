<?php

namespace App\Services\Operations;

use App\Enums\ArtifactStatus;
use App\Enums\DeviceRegistrationStatus;
use App\Enums\InstallationStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\DeviceRegistration;
use App\Models\Installation;
use App\Models\MetricSnapshot;
use App\Models\PipelineJob;
use App\Models\SignedBuild;
use App\Services\Apple\AppStoreConnectIntegration;
use App\Services\Quotas\QuotaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes the FULL_PLAN §14 metrics and stores them as metric_snapshots
 * (IMPLEMENTATION_PLAN P8-OPS-02). Rates cover the last 24 hours.
 */
class MetricsCollector
{
    public function __construct(private readonly QuotaService $quotas) {}

    /**
     * @return array<string, float|array<int, array{labels: array<string, string>, value: float}>>
     */
    public function collect(): array
    {
        $since = now()->subDay();
        [$requests, $errors, $milliseconds] = $this->requestTotals(60);

        $metrics = [
            'api_request_duration' => $requests > 0 ? round($milliseconds / $requests, 1) : 0.0,
            'api_error_rate' => $requests > 0 ? round($errors / $requests, 4) : 0.0,
            'device_registration_success_rate' => $this->rate(
                DeviceRegistration::query()->where('updated_at', '>=', $since)->where('status', DeviceRegistrationStatus::Eligible->value)->count(),
                DeviceRegistration::query()->where('updated_at', '>=', $since)->where('status', DeviceRegistrationStatus::AppleFailed->value)->count(),
            ),
            'apple_api_429_count' => (float) Cache::get(AppStoreConnectIntegration::METRIC_429, 0),
            'artifact_inspection_failures' => (float) AppArtifact::query()->where('updated_at', '>=', $since)
                ->whereIn('status', [ArtifactStatus::InspectionFailed->value, ArtifactStatus::Rejected->value, ArtifactStatus::Quarantined->value])->count(),
            'signing_success_rate' => $this->rate(
                SignedBuild::query()->where('updated_at', '>=', $since)->where('status', SignedBuildStatus::Deliverable->value)->count(),
                SignedBuild::query()->where('updated_at', '>=', $since)->whereIn('status', [SignedBuildStatus::SigningFailed->value, SignedBuildStatus::ValidationFailed->value])->count(),
            ),
            'install_authorization_success_rate' => $this->rate(
                Installation::query()->where('updated_at', '>=', $since)->where('status', InstallationStatus::Delivered->value)->count(),
                DB::table('install_authorizations')->where('created_at', '>=', $since)->count() - Installation::query()->where('updated_at', '>=', $since)->where('status', InstallationStatus::Delivered->value)->count(),
            ),
            'queue_depth' => (float) (PipelineJob::query()->whereIn('status', [PipelineJobStatus::Queued->value, PipelineJobStatus::FailedRetryable->value])->count()
                + DB::table('jobs')->count()),
            'artifact_storage_bytes' => (float) (AppArtifact::query()->whereNull('purged_at')->sum('size_bytes')
                + SignedBuild::query()->whereNull('purged_at')->whereNotNull('storage_path')->sum('size_bytes')),
            'quota_remaining_by_team_family' => $this->quotaRemaining(),
        ];

        $now = now();
        foreach ($metrics as $name => $value) {
            foreach (is_array($value) ? $value : [['labels' => [], 'value' => $value]] as $point) {
                MetricSnapshot::create(['name' => $name, 'labels' => $point['labels'] ?: null, 'value' => $point['value'], 'captured_at' => $now]);
            }
        }

        return $metrics;
    }

    /**
     * Latest value of every metric, for the admin dashboard.
     *
     * @return list<array{name: string, labels: array<string, string>|null, value: float, captured_at: string}>
     */
    public function latest(): array
    {
        $last = MetricSnapshot::query()->max('captured_at');
        if ($last === null) {
            return [];
        }

        return MetricSnapshot::query()->where('captured_at', $last)->orderBy('name')->get()
            ->map(fn (MetricSnapshot $snapshot) => [
                'name' => $snapshot->name,
                'labels' => $snapshot->labels,
                'value' => $snapshot->value,
                'captured_at' => $snapshot->captured_at->toIso8601ZuluString(),
            ])->all();
    }

    /**
     * @return array{0: int, 1: int, 2: int} Requests, 5xx answers and total milliseconds over the last minutes.
     */
    private function requestTotals(int $minutes): array
    {
        $totals = [0, 0, 0];
        for ($i = 0; $i < $minutes; $i++) {
            $bucket = 'req-metrics:'.now()->subMinutes($i)->format('YmdHi');
            $totals[0] += (int) Cache::get($bucket.':count', 0);
            $totals[1] += (int) Cache::get($bucket.':errors', 0);
            $totals[2] += (int) Cache::get($bucket.':ms', 0);
        }

        return $totals;
    }

    private function rate(int $good, int $bad): float
    {
        return $good + $bad === 0 ? 1.0 : round($good / ($good + $bad), 4);
    }

    /**
     * @return list<array{labels: array<string, string>, value: float}>
     */
    private function quotaRemaining(): array
    {
        $points = [];
        foreach (AppleTeam::query()->get() as $team) {
            foreach ($this->quotas->summary($team) as $quota) {
                $points[] = ['labels' => ['team' => $team->apple_team_id, 'family' => $quota['family']], 'value' => (float) $quota['remaining']];
            }
        }

        return $points;
    }
}
