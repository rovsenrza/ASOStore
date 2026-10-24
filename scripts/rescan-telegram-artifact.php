<?php

declare(strict_types=1);

// php rescan-telegram-artifact.php --list
// php rescan-telegram-artifact.php ARTIFACT_PUBLIC_ID
// Run each scan in a fresh PHP process to release inspector resources between files.

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Inspection\MalwareScanner;
use App\StateMachines\StateMachine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use App\Services\Artifacts\LocalArtifactFile;
use Illuminate\Support\Facades\Storage;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === '--list') {
    AppArtifact::where('status', ArtifactStatus::ProvenanceReview->value)
        ->where('source_type', 'CUSTOMER_PROVIDED')
        ->whereHas('app', fn ($query) => $query->where('slug', 'like', 'tg-%'))
        ->orderBy('id')->chunk(100, function ($artifacts): void {
            foreach ($artifacts as $artifact) {
                if (($artifact->inspection['malware_scan']['status'] ?? null) === MalwareScanner::UNAVAILABLE) {
                    echo $artifact->public_id."\n";
                }
            }
        });
    exit(0);
}

$id = $argv[1] ?? '';
$artifact = AppArtifact::where('public_id', $id)->firstOrFail();
if ($artifact->status !== ArtifactStatus::ProvenanceReview
    || ($artifact->inspection['malware_scan']['status'] ?? null) !== MalwareScanner::UNAVAILABLE) {
    echo "SKIP {$id}\n";
    exit(0);
}

$file = LocalArtifactFile::open(Storage::disk($artifact->storage_disk), $artifact->storage_path);
try {
    $scan = $app->make(MalwareScanner::class)->scan($file->path);
} finally {
    $file->release();
}
if ($scan['status'] === MalwareScanner::UNAVAILABLE) {
    echo "UNAVAILABLE {$id} ".($scan['note'] ?? '')."\n";
    exit(1);
}

DB::transaction(function () use ($app, $artifact, $scan): void {
    $report = $artifact->inspection ?? [];
    $report['malware_scan'] = $scan + ['rescanned_at' => now()->toIso8601ZuluString()];
    $artifact->forceFill(['inspection' => $report])->save();
    $app->make(AuditService::class)->record('artifact.malware_rescanned', $artifact, after: [
        'status' => $scan['status'],
        'signature' => $scan['signature'] ?? null,
    ], actor: Actor::system('telegram-import-rescan'));
    if ($scan['status'] === MalwareScanner::INFECTED) {
        $app->make(StateMachine::class)->transition($artifact, ArtifactStatus::Quarantined,
            'Malware found during fresh-process rescan.', Actor::system('telegram-import-rescan'),
            extra: ['status_reason' => 'MALWARE_DETECTED']);
    }
});

echo $scan['status']." {$id}\n";
