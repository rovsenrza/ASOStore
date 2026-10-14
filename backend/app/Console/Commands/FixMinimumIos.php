<?php

namespace App\Console\Commands;

use App\Models\AppArtifact;
use App\Models\AppVersion;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Inspection\IpaInspector;
use Illuminate\Console\Command;

/**
 * Inspection used to take the minimum iOS from Info.plist alone, and hand-edited IPAs claim
 * 10.0 there while their executable needs 17.6. Installs then went through on older iPhones
 * and the app quit at launch. This raises stored artifacts (and their catalog versions) to the
 * executable's own minimum, read from the inspection report, so no IPA is downloaded.
 */
class FixMinimumIos extends Command
{
    protected $signature = 'ipa:fix-min-ios {--apply : Save the corrections (without it, only list them)}';

    protected $description = 'Raise artifacts whose executable needs a newer iOS than their Info.plist declares';

    public function handle(AuditService $audit): int
    {
        $rows = [];
        foreach (AppArtifact::query()->whereNull('purged_at')->whereNotNull('inspection')->with('app')->orderBy('id')->lazy() as $artifact) {
            $binary = collect($artifact->inspection['binaries'] ?? [])->firstWhere('role', 'main');
            $needed = is_array($binary) && is_string($binary['min_os'] ?? null) && ($binary['platform'] ?? null) === 'ios' ? $binary['min_os'] : null;
            $declared = $artifact->min_ios_version;
            if ($needed === null || ! IpaInspector::newerVersion($needed, $declared)) {
                continue;
            }
            $rows[] = [$artifact->id, $artifact->app?->name ?? '-', $artifact->status->value, $declared ?? '-', $needed];
            if (! $this->option('apply')) {
                continue;
            }

            $inspection = $artifact->inspection;
            $inspection['bundle']['declared_min_ios_version'] ??= $declared;
            $inspection['bundle']['min_ios_version'] = $needed;
            $artifact->forceFill(['min_ios_version' => $needed, 'inspection' => $inspection])->save();
            if ($artifact->app_version_id !== null) {
                AppVersion::query()->whereKey($artifact->app_version_id)->update(['min_ios_version' => $needed]);
            }
            $audit->record('artifact.min_ios_corrected', $artifact, before: ['min_ios_version' => $declared], after: ['min_ios_version' => $needed], actor: Actor::system('inspection'));
        }

        $this->table(['artifact', 'app', 'status', 'declared', 'executable needs'], $rows);
        $this->line(sprintf('%d artifact(s) %s.', count($rows), $this->option('apply') ? 'corrected' : 'would be corrected; run with --apply to save'));

        return self::SUCCESS;
    }
}
