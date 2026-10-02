<?php

namespace App\Console\Commands;

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Services\Artifacts\IpaCleaner;
use App\Services\Artifacts\LocalArtifactFile;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * New uploads are analyzed during inspection; this adds the same analysis to IPAs that
 * were stored before, so the admin panel can offer their cleanup too.
 */
class AnalyzeArtifacts extends Command
{
    protected $signature = 'ipa:analyze {artifact?* : Public IDs of artifacts} {--status=PUBLISHED : Without IDs, artifacts in this status that have no analysis yet} {--limit=20 : At most this many (each IPA is downloaded once)}';

    protected $description = 'Run the tools/ipa-cleaner analysis on stored IPAs and add it to their inspection report';

    public function handle(IpaCleaner $cleaner, AuditService $audit): int
    {
        if (! $cleaner->enabled()) {
            $this->error('The IPA cleaner is not installed (storefront.ipa_cleaner).');

            return self::FAILURE;
        }
        $ids = array_map('strtolower', (array) $this->argument('artifact'));
        $status = ArtifactStatus::tryFrom((string) $this->option('status'));
        if ($ids === [] && $status === null) {
            $this->error('Unknown status; use one of: '.implode(', ', array_column(ArtifactStatus::cases(), 'value')));

            return self::FAILURE;
        }
        $artifacts = AppArtifact::query()->whereNull('purged_at')
            ->when($ids !== [], fn ($query) => $query->whereIn('public_id', $ids))
            ->when($ids === [], fn ($query) => $query->where('status', $status?->value)->whereNull('inspection->cleaning'))
            ->with('app')->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();

        $rows = [];
        foreach ($artifacts as $artifact) {
            $file = LocalArtifactFile::open(Storage::disk($artifact->storage_disk), $artifact->storage_path);
            try {
                $analysis = $cleaner->analyze($file->path);
            } finally {
                $file->release();
            }
            if ($analysis === null) {
                continue;
            }
            $artifact->forceFill(['inspection' => ['cleaning' => $analysis] + ($artifact->inspection ?? [])])->save();
            $audit->record('artifact.cleaning_analyzed', $artifact, after: [
                'recommended' => $analysis['recommended'] ?? null,
                'modules' => count($analysis['modules'] ?? []),
                'error' => $analysis['error'] ?? null,
            ], actor: Actor::system('ipa-cleaner'));
            $rows[] = [
                $artifact->public_id,
                $artifact->app?->name,
                count($analysis['recommended']['remove'] ?? []) + count($analysis['recommended']['patch'] ?? []),
                count($analysis['modules'] ?? []),
                count($analysis['encrypted_binaries'] ?? []),
                $analysis['error'] ?? '',
            ];
        }
        $this->table(['artifact', 'app', 'recommended', 'modules', 'encrypted', 'error'], $rows);

        return self::SUCCESS;
    }
}
