<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Services\Artifacts\ColdStorage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings a cold original back from the bucket ahead of its install (ColdStorage::prefetch), so the
 * runner and the download find it on the local disk.
 */
class HydrateArtifactJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1700;

    public int $uniqueFor = 1800;

    public function __construct(public int $artifactId)
    {
        $this->onQueue('files');
    }

    public function uniqueId(): string
    {
        return (string) $this->artifactId;
    }

    public function handle(ColdStorage $cold): void
    {
        $artifact = AppArtifact::query()->find($this->artifactId);
        if ($artifact !== null) {
            $cold->ensureLocal($artifact);
        }
    }
}
