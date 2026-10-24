<?php

declare(strict_types=1);

/** Storage-only maintenance. Catalog, audit rows, credentials and live builds stay. */
final class StoragePrunePolicy
{
    public const ARTIFACT_TERMINAL = ['EXPIRED', 'REVOKED', 'REJECTED', 'INSPECTION_FAILED', 'PROVENANCE_FAILED'];
    public const BUILD_TERMINAL = ['EXPIRED', 'REVOKED', 'SIGNING_FAILED', 'VALIDATION_FAILED'];

    public static function managed(string $path): bool
    {
        return preg_match('~^(originals/[a-f0-9]{2}/[a-f0-9]{64}|signed/[a-z0-9]{26})\.ipa$~D', $path) === 1;
    }

    /** Any live reference wins over expired/rejected references to shared bytes. */
    public static function reason(string $path, array $references, int $mtime, int $now): ?string
    {
        if (! self::managed($path)) {
            return null;
        }
        foreach ($references as $reference) {
            $terminal = $reference['kind'] === 'artifact' ? self::ARTIFACT_TERMINAL : self::BUILD_TERMINAL;
            if (! in_array($reference['status'], $terminal, true)) {
                return null;
            }
        }
        if ($references !== []) {
            return 'terminal_references';
        }
        return $mtime < $now - 86400 ? 'unreferenced_older_than_24h' : null;
    }

    public static function remoteMd5(array $head): ?string
    {
        $encoded = $head['Metadata']['md5chksum'] ?? null;
        if (is_string($encoded)) {
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && strlen($decoded) === 16) {
                return bin2hex($decoded);
            }
        }
        $etag = trim((string) ($head['ETag'] ?? ''), '"');
        return preg_match('/^[a-f0-9]{32}$/D', $etag) === 1 ? $etag : null;
    }

    public static function abandonedMultipart(string $path, array $refs, int $lastPart, int $now, bool $completed): bool
    {
        if (! self::managed($path) || $lastPart >= $now - 3600) {
            return false;
        }
        if ($refs === []) {
            return $lastPart < $now - 86400;
        }
        foreach ($refs as $ref) {
            $terminal = $ref['kind'] === 'artifact' ? self::ARTIFACT_TERMINAL : self::BUILD_TERMINAL;
            if (! in_array($ref['status'], $terminal, true)
                && ! ($ref['kind'] === 'build' && $ref['status'] === 'DELIVERABLE' && $completed)) {
                return false;
            }
        }
        return true;
    }
}

// Allow the safety policy to be exercised without booting production Laravel.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

require '/var/www/storefront/app/backend/vendor/autoload.php';
$app = require '/var/www/storefront/app/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$reportPath = $argv[2] ?? '';
if (! in_array($mode, ['plan', 'apply'], true) || $reportPath === '') {
    throw new RuntimeException('Usage: prune-artifact-storage.php plan|apply REPORT.json');
}
if (config('filesystems.disks.artifacts.driver') !== 's3' || config('filesystems.disks.artifacts.root')) {
    throw new RuntimeException('Requires the verified S3-backed artifact disk without a prefix');
}
$disk = Illuminate\Support\Facades\Storage::disk('artifacts');
$bucket = config('filesystems.disks.artifacts.bucket');
$client = $disk->getClient();
$actor = App\Services\Audit\Actor::system('user-requested-storage-cleanup-20261002');
$legacy = storage_path('app/artifacts');
$cacheRoot = config('storefront.artifacts.file_cache_path', storage_path('app/private/artifact-cache'));

function references(string $path): array
{
    $rows = [];
    foreach (App\Models\AppArtifact::where('storage_disk', 'artifacts')->where('storage_path', $path)->get() as $row) {
        $rows[] = ['kind' => 'artifact', 'id' => $row->id, 'status' => $row->status->value];
    }
    $builds = App\Models\SignedBuild::where(function ($query) use ($path): void {
        $query->where('storage_path', $path);
        // A failed upload can leave storage_path null while its object/parts
        // already exist under the build's public ID.
        if (preg_match('~^signed/([a-z0-9]{26})\.ipa$~D', $path, $match)) {
            $query->orWhere('public_id', $match[1]);
        }
    })->get();
    foreach ($builds as $row) {
        $rows[] = ['kind' => 'build', 'id' => $row->id, 'status' => $row->status->value];
    }
    return $rows;
}

function mediaReferences(): array
{
    $paths = [];
    foreach (App\Models\CatalogApp::withTrashed()->get(['icon_path', 'banner_path']) as $row) {
        foreach ([$row->icon_path, $row->banner_path] as $path) {
            if ($path) {
                $paths[$path] = true;
            }
        }
    }
    foreach (App\Models\AppScreenshot::pluck('path') as $path) {
        $paths[$path] = true;
    }
    return $paths;
}

function saveReport(string $path, array $report): void
{
    file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    chmod($path, 0600);
}

function multipartParts($client, string $bucket, string $key, string $id): array
{
    $parts = [];
    $marker = 0;
    do {
        $page = $client->listParts(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $id, 'PartNumberMarker' => $marker]);
        foreach ($page['Parts'] ?? [] as $part) {
            $parts[] = ['number' => $part['PartNumber'], 'etag' => $part['ETag'], 'bytes' => $part['Size'],
                'mtime' => $part['LastModified']->getTimestamp()];
        }
        $marker = $page['NextPartNumberMarker'] ?? 0;
    } while ($page['IsTruncated']);
    return $parts;
}

if ($mode === 'plan') {
    $report = ['created_at' => now()->toIso8601String(), 'objects_before' => [], 's3' => [], 'multipart' => [], 'legacy' => [], 'cache' => [], 'media' => [], 'kept_legacy' => []];
    foreach ($disk->getDriver()->listContents('', true) as $object) {
        if (! $object->isFile()) {
            continue;
        }
        $item = ['path' => $object->path(), 'bytes' => $object->fileSize(), 'mtime' => $object->lastModified()];
        $report['objects_before'][] = $item;
        $reason = StoragePrunePolicy::reason($item['path'], references($item['path']), $item['mtime'], time());
        if ($reason !== null) {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $item['path']]);
            $report['s3'][] = $item + ['reason' => $reason, 'etag' => $head['ETag']];
        }
    }
    $options = ['Bucket' => $bucket];
    do {
        $uploads = $client->listMultipartUploads($options);
        foreach ($uploads['Uploads'] ?? [] as $upload) {
            $parts = multipartParts($client, $bucket, $upload['Key'], $upload['UploadId']);
            $last = max([$upload['Initiated']->getTimestamp(), ...array_column($parts, 'mtime')]);
            if (StoragePrunePolicy::abandonedMultipart($upload['Key'], references($upload['Key']), $last, time(), $disk->exists($upload['Key']))) {
                $report['multipart'][] = ['path' => $upload['Key'], 'upload_id' => $upload['UploadId'], 'parts' => $parts,
                    'last_part' => $last, 'bytes' => array_sum(array_column($parts, 'bytes'))];
            }
        }
        $options['KeyMarker'] = $uploads['NextKeyMarker'] ?? '';
        $options['UploadIdMarker'] = $uploads['NextUploadIdMarker'] ?? '';
    } while ($uploads['IsTruncated']);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($legacy, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($legacy) + 1);
        if (! StoragePrunePolicy::managed($relative)) {
            continue;
        }
        $item = ['path' => $relative, 'bytes' => $file->getSize(), 'md5' => hash_file('md5', $file->getPathname())];
        $refs = references($relative);
        $terminal = StoragePrunePolicy::reason($relative, $refs, $file->getMTime(), time()) !== null && $refs !== [];
        if ($terminal) {
            $report['legacy'][] = $item + ['reason' => 'terminal_references'];
        } elseif ($disk->exists($relative)) {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $relative]);
            $md5 = StoragePrunePolicy::remoteMd5($head->toArray());
            if ($md5 === null) {
                $stream = $disk->readStream($relative);
                if ($stream === false) {
                    throw new RuntimeException('Cannot verify remote duplicate');
                }
                $hash = hash_init('md5');
                hash_update_stream($hash, $stream);
                fclose($stream);
                $md5 = hash_final($hash);
            }
            if ((int) $head['ContentLength'] === $item['bytes'] && hash_equals($item['md5'], $md5)) {
                $report['legacy'][] = $item + ['reason' => 'verified_s3_duplicate', 'etag' => $head['ETag']];
            } else {
                $report['kept_legacy'][] = $item + ['reason' => 'remote_checksum_mismatch'];
            }
        } else {
            $report['kept_legacy'][] = $item + ['reason' => 'no_verified_remote_copy'];
        }
        if (count($report['legacy']) % 25 === 0) {
            echo json_encode(['verified_local_files' => count($report['legacy'])])."\n";
        }
    }
    foreach (glob($cacheRoot.'/*.ipa') ?: [] as $path) {
        $sha = basename($path, '.ipa');
        $relative = 'originals/'.substr($sha, 0, 2).'/'.$sha.'.ipa';
        if (time() - filemtime($path) > 300 && StoragePrunePolicy::reason($relative, references($relative), filemtime($path), time()) !== null) {
            $report['cache'][] = ['path' => basename($path), 'bytes' => filesize($path)];
        }
    }
    $public = Illuminate\Support\Facades\Storage::disk('public');
    $media = mediaReferences();
    foreach ($public->allFiles('catalog') as $path) {
        if (! isset($media[$path]) && $public->lastModified($path) < time() - 300) {
            $report['media'][] = ['path' => $path, 'bytes' => $public->size($path), 'mtime' => $public->lastModified($path)];
        }
    }
    saveReport($reportPath, $report);
    echo json_encode(array_map(fn ($items) => ['files' => count($items), 'bytes' => array_sum(array_column($items, 'bytes'))], array_intersect_key($report, array_flip(['s3', 'multipart', 'legacy', 'cache', 'media', 'kept_legacy']))))."\n";
    exit;
}

$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (strtotime($report['created_at']) < time() - 7200) {
    throw new RuntimeException('Plan is too old; create a fresh plan');
}
$result = ['s3' => [], 'multipart' => [], 'legacy' => [], 'cache' => [], 'media' => [], 'skipped' => []];
foreach ($report['multipart'] ?? [] as $item) {
    $parts = multipartParts($client, $bucket, $item['path'], $item['upload_id']);
    if ($parts !== $item['parts'] || ! StoragePrunePolicy::abandonedMultipart($item['path'], references($item['path']), $item['last_part'], time(), $disk->exists($item['path']))) {
        $result['skipped'][] = ['path' => $item['path'], 'reason' => 'multipart_changed'];
        continue;
    }
    $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $item['path'], 'UploadId' => $item['upload_id']]);
    $result['multipart'][] = $item;
    saveReport($reportPath.'.applied.json', $result);
}
foreach ($report['s3'] as $item) {
    $key = $item['path'];
    // Re-read references immediately before deletion; a shared/live file is protected.
    if (StoragePrunePolicy::reason($key, references($key), $item['mtime'], time()) === null) {
        $result['skipped'][] = ['path' => $key, 'reason' => 'reference_changed'];
        continue;
    }
    if ($disk->exists($key)) {
        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        if ($head['ETag'] !== $item['etag'] || (int) $head['ContentLength'] !== $item['bytes']) {
            $result['skipped'][] = ['path' => $key, 'reason' => 'object_changed'];
            continue;
        }
        if (! $disk->delete($key) || $disk->exists($key)) {
            throw new RuntimeException('Object deletion could not be verified: '.$key);
        }
    }
    App\Models\AppArtifact::where('storage_disk', 'artifacts')->where('storage_path', $key)->whereIn('status', StoragePrunePolicy::ARTIFACT_TERMINAL)->whereNull('purged_at')->update(['purged_at' => now()]);
    App\Models\SignedBuild::where('storage_path', $key)->whereIn('status', StoragePrunePolicy::BUILD_TERMINAL)->whereNull('purged_at')->update(['purged_at' => now()]);
    $result['s3'][] = $item;
    saveReport($reportPath.'.applied.json', $result);
}
foreach ($report['legacy'] as $item) {
    if (! StoragePrunePolicy::managed($item['path'])) {
        throw new RuntimeException('Unmanaged local path');
    }
    $path = $legacy.'/'.$item['path'];
    if (! is_file($path) || is_link($path)) {
        continue;
    }
    if (filesize($path) !== $item['bytes'] || hash_file('md5', $path) !== $item['md5']) {
        $result['skipped'][] = ['path' => $path, 'reason' => 'local_changed'];
        continue;
    }
    if ($item['reason'] === 'terminal_references') {
        if (StoragePrunePolicy::reason($item['path'], references($item['path']), filemtime($path), time()) === null) {
            continue;
        }
    } else {
        if (! $disk->exists($item['path'])) {
            continue;
        }
        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $item['path']]);
        if ($head['ETag'] !== $item['etag']) {
            continue;
        }
    }
    unlink($path);
    $result['legacy'][] = $item;
}
$lock = fopen($cacheRoot.'/.lock', 'c');
if ($lock !== false && flock($lock, LOCK_EX)) {
    foreach ($report['cache'] as $item) {
        if (! preg_match('/^[a-f0-9]{64}\.ipa$/D', $item['path'])) {
            continue;
        }
        $path = $cacheRoot.'/'.$item['path'];
        $sha = basename($path, '.ipa');
        $relative = 'originals/'.substr($sha, 0, 2).'/'.$sha.'.ipa';
        if (is_file($path) && ! is_link($path) && filemtime($path) < time() - 300
            && StoragePrunePolicy::reason($relative, references($relative), filemtime($path), time()) !== null) {
            unlink($path);
            if (is_file($path.'.json')) {
                unlink($path.'.json');
            }
            $result['cache'][] = $item;
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
$public = Illuminate\Support\Facades\Storage::disk('public');
$media = mediaReferences();
foreach ($report['media'] as $item) {
    if (str_starts_with($item['path'], 'catalog/') && ! str_contains($item['path'], '..') && ! isset($media[$item['path']])
        && $public->exists($item['path']) && $public->lastModified($item['path']) === $item['mtime']) {
        $public->delete($item['path']);
        $result['media'][] = $item;
    }
}
saveReport($reportPath.'.applied.json', $result);
$summary = array_map(fn ($items) => ['files' => count($items), 'bytes' => array_sum(array_column($items, 'bytes'))], $result);
$app->make(App\Services\Audit\AuditService::class)->record('storage.cleanup_applied', after: $summary, actor: $actor);
echo json_encode($summary)."\n";
