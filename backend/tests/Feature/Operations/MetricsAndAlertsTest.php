<?php

use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\MetricSnapshot;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Services\Operations\AlertEvaluator;
use App\Services\Operations\MetricsCollector;
use App\Services\Signing\SigningService;
use Illuminate\Support\Facades\Log;

it('collects every FULL_PLAN metric and shows the latest values to operators', function () {
    connectFakeAppleTeam();
    $this->getJson('/api/v1/health')->assertOk();
    $this->getJson('/api/v1/health')->assertOk();

    $metrics = app(MetricsCollector::class)->collect();

    expect(array_keys($metrics))->toBe([
        'api_request_duration', 'api_error_rate', 'device_registration_success_rate', 'apple_api_429_count',
        'artifact_inspection_failures', 'signing_success_rate', 'install_authorization_success_rate',
        'queue_depth', 'artifact_storage_bytes', 'quota_remaining_by_team_family',
    ])->and($metrics['api_error_rate'])->toBe(0.0)
        ->and(MetricSnapshot::where('name', 'api_request_duration')->count())->toBe(1);

    asStaff(userWithRoles(RoleSlug::Support))->getJson('/api/v1/admin/metrics')
        ->assertOk()
        ->assertJsonFragment(['name' => 'queue_depth']);
});

it('raises each alert once per hour through the alerts channel and the audit log', function () {
    Log::shouldReceive('channel')->with('alerts')->andReturnSelf();
    Log::shouldReceive('log')->atLeast()->once();
    config(['storefront.alerts.backup_status_file' => '/nonexistent/backup-status.json']);

    // Signing work waits, but the only runner stopped sending heartbeats.
    Runner::create(['key_id' => 'rk_old', 'name' => 'mac', 'secret_encrypted' => 'x', 'last_heartbeat_at' => now()->subHour()]);
    PipelineJob::factory()->create(['type' => SigningService::RUNNER_JOB_TYPE]);

    $first = app(AlertEvaluator::class)->run();
    $second = app(AlertEvaluator::class)->run();

    expect($first)->toContain('runner-offline', 'backup')
        ->and($second)->toBe([])
        ->and(AuditLog::where('action', 'alert.raised')->count())->toBe(count($first));
});

it('passes the backup check when the latest verified backup is recent', function () {
    $file = tempnam(sys_get_temp_dir(), 'backup');
    file_put_contents($file, json_encode(['finished_at' => now()->subHour()->toIso8601ZuluString(), 'verified' => true]));
    config(['storefront.alerts.backup_status_file' => $file]);

    expect(app(AlertEvaluator::class)->run())->not->toContain('backup');
    unlink($file);
});
