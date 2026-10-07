<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a published IPA into a reviewed cleaned copy that is ready to publish but not yet
 * live: tools/ipa-cleaner removes the injected modules config('storefront.catalog_clean')
 * selects, the copy goes through the normal inspection and is approved. Publishing it
 * (which supersedes the live build) is the caller's decision: catalog:clean-batch does it
 * at once, catalog:device-test only after the copy passed on a device.
 *
 * Resumable: a copy of the same source still on its way, or already ready, is picked up
 * again instead of cleaning twice.
 */
class CleanedCopyBuilder
{
    /** A copy in one of these states is still useful. */
    private const PENDING = [ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting, ArtifactStatus::ProvenanceReview, ArtifactStatus::Ready];

    public function __construct(
        private readonly ArtifactCleaningService $cleaning,
        private readonly ArtifactReviewService $review,
        private readonly IpaCleaner $cleaner,
        private readonly AuditService $audit,
    ) {}

    public function available(): bool
    {
        return $this->cleaner->enabled();
    }

    /**
     * The modules to remove: those whose file name matches `remove`, never a name in `keep`.
     * A bundled hook runtime (Substrate) goes only when nothing that could still use it stays.
     *
     * @param  array<string, mixed>  $analysis
     * @param  array{remove: list<string>, keep: list<string>, runtime: list<string>}  $rules
     * @return array{remove: list<string>, remove_extensions: list<string>, fix_metadata: bool}
     */
    public static function select(array $analysis, array $rules): array
    {
        $matches = fn (string $name, array $patterns) => array_filter($patterns, fn (string $pattern) => fnmatch(strtolower($pattern), strtolower($name))) !== [];
        $remove = [];
        $stays = false;
        foreach ($analysis['modules'] ?? [] as $module) {
            $name = basename((string) $module['path']);
            $isRuntime = $matches($name, $rules['runtime'] ?? []);
            if ($isRuntime) {
                continue;
            }
            if (! ($module['removable'] ?? false) || $matches($name, $rules['keep'] ?? []) || ! $matches($name, $rules['remove'] ?? [])) {
                $stays = true;

                continue;
            }
            $remove[] = (string) $module['path'];
        }
        if ($remove !== [] && ! $stays) {
            foreach ($analysis['modules'] ?? [] as $module) {
                if ($matches(basename((string) $module['path']), $rules['runtime'] ?? []) && ($module['removable'] ?? false)) {
                    $remove[] = (string) $module['path'];
                }
            }
        }

        return ['remove' => array_values(array_unique($remove)), 'remove_extensions' => [], 'fix_metadata' => true];
    }

    /**
     * The cleaner's analysis of the artifact, stored in its inspection the first time.
     *
     * @return array<string, mixed>|null
     */
    public function analysis(AppArtifact $artifact, bool $store = true): ?array
    {
        $existing = $artifact->inspection['cleaning'] ?? null;
        if (is_array($existing) && ! isset($existing['error'])) {
            return $existing;
        }
        $file = LocalArtifactFile::open(Storage::disk($artifact->storage_disk), $artifact->storage_path);
        try {
            $analysis = $this->cleaner->analyze($file->path);
        } finally {
            $file->release();
        }
        if ($analysis !== null && $store) {
            $artifact->forceFill(['inspection' => ['cleaning' => $analysis] + ($artifact->inspection ?? [])])->save();
            $this->audit->record('artifact.cleaning_analyzed', $artifact, after: ['modules' => count($analysis['modules'] ?? [])], actor: Actor::system('ipa-cleaner'));
        }

        return $analysis;
    }

    /**
     * Makes (or resumes) the cleaned, approved copy of a published artifact. `result` is
     * READY when `copy` can be published; any other value says why there is none.
     *
     * @return array{result: string, detail: string, copy: AppArtifact|null, removed: list<string>}
     */
    public function prepare(AppArtifact $artifact, User $user, string $purpose, int $waitSeconds = 1800): array
    {
        $outcome = fn (string $result, string $detail = '', ?AppArtifact $copy = null, array $removed = []) => compact('result', 'detail', 'copy', 'removed');

        $analysis = $this->analysis($artifact);
        if ($analysis === null || isset($analysis['error'])) {
            return $outcome('NO_ANALYSIS', (string) ($analysis['error'] ?? 'analysis unavailable'));
        }
        $selection = self::select($analysis, config('storefront.catalog_clean'));
        $names = array_map('basename', $selection['remove']);
        if ($selection['remove'] === []) {
            return $outcome('NOTHING_TO_REMOVE', 'modules: '.implode(',', array_map('basename', array_column($analysis['modules'] ?? [], 'path'))));
        }

        $copy = $this->pendingCopy($artifact);
        if ($copy === null) {
            $job = $this->cleaning->request($artifact, $user, $selection, $purpose.': remove '.implode(', ', $names), null);
            $job = $this->waitForJob($job, $waitSeconds);
            if ($job->status !== PipelineJobStatus::Succeeded) {
                return $outcome('CLEAN_FAILED', $job->status->value.' '.$job->result_code.' '.mb_substr((string) ($job->error_class ?? ''), 0, 300), null, $names);
            }
            if ($job->result_code === 'NOTHING_TO_CLEAN') {
                return $outcome('NOTHING_TO_CLEAN', implode(',', $names), null, $names);
            }
            // ALREADY_EXISTS: the same bytes were produced before; use that copy if it is still usable.
            $copy = $this->pendingCopy($artifact);
            if ($copy === null) {
                return $outcome($job->result_code === 'ALREADY_EXISTS' ? 'ALREADY_EXISTS' : 'NO_COPY', 'cleaner reported '.$job->result_code, null, $names);
            }
        }

        $copy = $this->waitForInspection($copy, $waitSeconds);
        if ($copy->status === ArtifactStatus::ProvenanceReview) {
            $this->review->approve($copy, $user, $purpose.' (cleaned copy): '.implode(', ', $names), [
                'source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true,
            ], false);
            $copy->refresh();
        }
        if ($copy->status !== ArtifactStatus::Ready) {
            return $outcome('NOT_READY', $copy->status->value.' '.($copy->status_reason ?? ''), $copy, $names);
        }

        return $outcome('READY', implode(',', $names), $copy, $names);
    }

    /** The newest cleaned copy of this source that can still become the live build. */
    public function pendingCopy(AppArtifact $artifact): ?AppArtifact
    {
        return AppArtifact::query()->where('derived_from_artifact_id', $artifact->id)
            ->whereIn('status', array_map(fn (ArtifactStatus $status) => $status->value, self::PENDING))
            ->whereNull('purged_at')->orderByDesc('id')->first();
    }

    private function waitForJob(PipelineJob $job, int $seconds): PipelineJob
    {
        $until = time() + $seconds;
        do {
            $job->refresh();
            if (in_array($job->status, [PipelineJobStatus::Succeeded, PipelineJobStatus::FailedPermanent, PipelineJobStatus::Cancelled], true)) {
                return $job;
            }
            sleep(5);
        } while (time() < $until);

        return $job;
    }

    private function waitForInspection(AppArtifact $copy, int $seconds): AppArtifact
    {
        $until = time() + $seconds;
        do {
            $copy->refresh();
            if (! in_array($copy->status, [ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting], true)) {
                return $copy;
            }
            sleep(5);
        } while (time() < $until);

        return $copy;
    }
}
