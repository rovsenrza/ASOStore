<?php

namespace App\Console\Commands;

use App\Models\AppLaunchReport;
use Illuminate\Console\Command;

/** What the compat shim reported from inside re-signed apps: launches, crashes, early exits. */
class LaunchReports extends Command
{
    protected $signature = 'apps:launch-reports
        {report? : One report\'s ID, shown in full with its backtrace}
        {--app= : Only this app (seo_slug or ID)}
        {--kind= : LAUNCHED, CRASHED or EXITED_EARLY}
        {--hours=48 : How far back}';

    protected $description = 'Show launch, crash and early-exit reports from re-signed apps';

    public function handle(): int
    {
        if ($id = $this->argument('report')) {
            $report = AppLaunchReport::query()->with(['app', 'team'])->find($id);
            if ($report === null) {
                $this->error("No report {$id}.");

                return self::FAILURE;
            }
            foreach ([
                'kind' => $report->kind, 'app' => $report->app?->name, 'bundle' => $report->bundle_identifier,
                'original bundle' => $report->original_bundle_identifier, 'team' => $report->team?->apple_team_id,
                'version' => $report->app_version, 'device' => $report->device_model, 'iOS' => $report->os_version,
                'shim' => $report->shim_version, 'happened' => $report->happened_at?->toDateTimeString(),
                'received' => $report->created_at?->toDateTimeString(), 'detail' => $report->detail,
            ] as $label => $value) {
                $this->line(sprintf('%-16s %s', $label, $value ?? '-'));
            }
            $this->line('');
            $this->line($report->backtrace ?? '(no backtrace)');

            return self::SUCCESS;
        }

        $app = $this->option('app');
        $reports = AppLaunchReport::query()->with(['app', 'team'])
            ->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))
            ->when($this->option('kind'), fn ($query, $kind) => $query->where('kind', strtoupper($kind)))
            ->when($app, fn ($query) => $query->whereHas('app', fn ($q) => is_numeric($app) ? $q->whereKey($app) : $q->where('seo_slug', $app)))
            ->latest('id')->limit(200)->get();

        $this->table(['id', 'when', 'kind', 'app', 'team', 'device', 'iOS', 'detail'], $reports->map(fn (AppLaunchReport $r) => [
            $r->id, $r->created_at?->format('m-d H:i'), $r->kind, mb_substr($r->app?->name ?? $r->bundle_identifier, 0, 24),
            $r->team?->apple_team_id, $r->device_model, $r->os_version, mb_substr((string) $r->detail, 0, 60),
        ]));

        return self::SUCCESS;
    }
}
