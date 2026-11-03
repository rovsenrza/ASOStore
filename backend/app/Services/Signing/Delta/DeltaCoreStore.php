<?php

namespace App\Services\Signing\Delta;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\DeltaCore;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Artifacts\LocalArtifactFile;
use App\Services\Pipeline\PipelineJobService;
use App\Services\Signing\SigningService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Receives the core of an artifact's delta v3 builds from the runner (docs/adr/DELTA_V3.md, "Core upload").
 *
 * Wire contract (PUT /api/worker/v1/jobs/{job}/core, HMAC-signed like every worker request; the lease holder only):
 *
 *  * Blob: body = the core blob (application/octet-stream, X-Content-SHA256 = sha256(body), hashed while
 *    streamed); `X-Core-Sha256` = sha256 of the body (lowercase hex); `X-Core-Manifest` = the manifest JSON,
 *    base64url (padding optional), only when small (the runner sends it up to 4 KiB).
 *  * Manifest, when it was not sent in the header: `PUT …/core?part=manifest`, body = the manifest JSON, same
 *    `X-Core-Sha256` (the blob it describes). The signature covers the path without the query string.
 *  * Either order works; the runner sends the blob first, then the manifest. Whichever part arrives first is kept
 *    as pending (answer 202 `{sha256, pending: true}`; a blob is written to cores/<sha>.bin, a manifest is kept an
 *    hour); the delta_cores row is created when both are present (answer 201 `{sha256, size_bytes, created: true}`).
 *    A blob nothing ever completes is removed by the janitor's object sweep after a day.
 *
 * Answers: 200 `{…, created: false}` when a core with that sha256 already exists (nothing is read or rewritten;
 * also for `part=manifest`); 422 VALIDATION_FAILED when the
 * hash differs from the body, the blob is larger than the source IPA, or the manifest is missing or invalid
 * (version 1, `artifact_sha256` = the build's source, entries under `Payload/`, items
 * `[start, end, sha256, blob_offset, blob_length, final]` in order, every blob range inside the blob);
 * 409 UPLOAD_CORRUPT when the body does not match the signed `X-Content-SHA256`.
 */
final class DeltaCoreStore
{
    private const PENDING_SECONDS = 3600;

    public function __construct(
        private readonly SigningService $signing,
        private readonly PipelineJobService $jobs,
        private readonly ArtifactFileCache $cache,
    ) {}

    /**
     * @return array{sha256: string, size_bytes: int, created: bool}|array{sha256: string, pending: true}
     */
    public function receiveManifest(PipelineJob $job, Runner $runner, string $sha256, string $json): array
    {
        $build = $this->signing->assertOwner($job, $runner);
        $artifact = $build->artifact;
        $sha256 = self::sha($sha256);
        if (($existing = DeltaCore::query()->where('sha256', $sha256)->first()) !== null) {
            return ['sha256' => $existing->sha256, 'size_bytes' => $existing->size_bytes, 'created' => false];
        }
        $manifest = self::decode($json);
        self::validate($manifest, (string) $artifact->sha256, null);

        // The blob came first (the runner's order): complete the core now.
        $size = Cache::get(self::blobKey($sha256));
        if (is_int($size) && Storage::disk('signed')->exists(DeltaCore::pathFor($sha256))) {
            self::validate($manifest, (string) $artifact->sha256, $size);

            return $this->record($job, $artifact->id, $sha256, $size, $manifest);
        }
        Cache::put(self::pendingKey($job, $sha256), $manifest, self::PENDING_SECONDS);

        return ['sha256' => $sha256, 'pending' => true];
    }

    /**
     * @param  resource  $body
     * @return array{sha256: string, size_bytes: int, created: bool}|array{sha256: string, size_bytes: int, pending: true}
     */
    public function receiveBlob(PipelineJob $job, Runner $runner, string $sha256, $body, ?string $manifestHeader, string $signedHash): array
    {
        $build = $this->signing->assertOwner($job, $runner);
        $artifact = $build->artifact;
        $sha256 = self::sha($sha256);
        if (($existing = DeltaCore::query()->where('sha256', $sha256)->first()) !== null) {
            return ['sha256' => $existing->sha256, 'size_bytes' => $existing->size_bytes, 'created' => false];
        }

        $manifest = null;
        if ($manifestHeader !== null && $manifestHeader !== '') {
            $decoded = base64_decode(strtr($manifestHeader, '-_', '+/'), true);
            if ($decoded === false) {
                throw new ApiException(ErrorCode::ValidationFailed, details: ['manifest' => 'X-Core-Manifest is not base64url.']);
            }
            $manifest = self::decode($decoded);
        } elseif (is_array($pending = Cache::get(self::pendingKey($job, $sha256)))) {
            $manifest = $pending;
        }
        if ($manifest !== null) {
            self::validate($manifest, (string) $artifact->sha256, null);
        }

        $limit = (int) $artifact->size_bytes;
        $context = hash_init('sha256');
        $size = 0;
        $temporaryPath = LocalArtifactFile::temporaryPath('core');
        $temporary = fopen($temporaryPath, 'w+b') ?: throw new RuntimeException('No temporary file available.');
        try {
            while (! feof($body)) {
                $chunk = fread($body, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new ApiException(ErrorCode::ValidationFailed, details: ['size_bytes' => 'The core is larger than the source IPA.']);
                }
                hash_update($context, $chunk);
                fwrite($temporary, $chunk);
            }
            $actual = hash_final($context);
            if ($size === 0 || ! hash_equals($sha256, $actual)) {
                throw new ApiException(ErrorCode::ValidationFailed, details: ['sha256' => 'X-Core-Sha256 does not match the body.', 'actual' => $actual]);
            }
            // The signature covered the declared body hash; the streamed bytes must match it.
            if (! hash_equals(strtolower($signedHash), $actual)) {
                throw new ApiException(ErrorCode::UploadCorrupt, details: ['sha256' => $actual, 'size_bytes' => $size]);
            }
            if ($manifest !== null) {
                self::validate($manifest, (string) $artifact->sha256, $size);
            }

            $path = DeltaCore::pathFor($sha256);
            $disk = Storage::disk('signed');
            for ($attempt = 1; ; $attempt++) {
                try {
                    rewind($temporary);
                    if ($disk->put($path, $temporary) === false) {
                        throw new RuntimeException("Unable to write file at location: {$path}.");
                    }

                    break;
                } catch (\Throwable $exception) {
                    if ($attempt >= 3) {
                        throw $exception;
                    }
                    report($exception);
                    usleep($attempt * 500_000);
                }
            }
            $this->cache->store($disk, $path, $sha256, $size, $temporary);
        } finally {
            fclose($temporary);
            @unlink($temporaryPath);
        }

        if ($manifest === null) {
            // The manifest follows in `?part=manifest`.
            Cache::put(self::blobKey($sha256), $size, self::PENDING_SECONDS);
            $this->jobs->extendLease($job, SigningService::LEASE_SECONDS);

            return ['sha256' => $sha256, 'size_bytes' => $size, 'pending' => true];
        }

        return $this->record($job, $artifact->id, $sha256, $size, $manifest);
    }

    /**
     * @param  array<mixed>  $manifest
     * @return array{sha256: string, size_bytes: int, created: bool}
     */
    private function record(PipelineJob $job, int $artifactId, string $sha256, int $size, array $manifest): array
    {
        try {
            $core = DeltaCore::create([
                'artifact_id' => $artifactId, 'sha256' => $sha256, 'size_bytes' => $size, 'manifest' => $manifest, 'storage_path' => DeltaCore::pathFor($sha256),
            ]);
            $created = true;
        } catch (UniqueConstraintViolationException) {
            // Another runner's first build of this artifact uploaded the same core meanwhile.
            $core = DeltaCore::query()->where('sha256', $sha256)->firstOrFail();
            $created = false;
        }
        Cache::forget(self::pendingKey($job, $sha256));
        Cache::forget(self::blobKey($sha256));
        $this->jobs->extendLease($job, SigningService::LEASE_SECONDS);

        return ['sha256' => $core->sha256, 'size_bytes' => $core->size_bytes, 'created' => $created];
    }

    /**
     * The manifest shape of docs/adr/DELTA_V3.md; with $blobSize also that every blob range lies inside the blob.
     *
     * @param  array<mixed>  $manifest
     */
    public static function validate(array $manifest, string $artifactSha256, ?int $blobSize): void
    {
        $fail = fn (string $why) => throw new ApiException(ErrorCode::ValidationFailed, details: ['manifest' => $why]);
        if (($manifest['version'] ?? null) !== 1) {
            $fail('version must be 1.');
        }
        if (! is_string($manifest['artifact_sha256'] ?? null) || ! hash_equals(strtolower($artifactSha256), strtolower($manifest['artifact_sha256']))) {
            $fail('artifact_sha256 is not this build\'s source.');
        }
        $entries = $manifest['entries'] ?? null;
        if (! is_array($entries) || $entries === [] || array_is_list($entries)) {
            $fail('entries must be a non-empty object.');
        }
        foreach ($entries as $name => $items) {
            if (! is_string($name) || ! str_starts_with($name, 'Payload/') || ! is_array($items) || $items === [] || ! array_is_list($items)) {
                $fail("Entry {$name} is malformed.");
            }
            $previousEnd = 0;
            $last = count($items) - 1;
            foreach ($items as $index => $item) {
                if (! is_array($item) || count($item) !== 6 || ! array_is_list($item)
                    || ! is_int($item[0]) || ! is_int($item[1]) || ! is_string($item[2]) || ! is_int($item[3]) || ! is_int($item[4]) || ! is_bool($item[5])
                    || preg_match('/^[a-f0-9]{64}$/', $item[2]) !== 1) {
                    $fail("Entry {$name} item {$index} is malformed.");
                }
                [$start, $end, , $offset, $length, $final] = $item;
                if ($start < $previousEnd || $end <= $start || $offset < 0 || $length <= 0 || ($final && $index !== $last)) {
                    $fail("Entry {$name} item {$index} is out of order or empty.");
                }
                if ($blobSize !== null && $offset + $length > $blobSize) {
                    $fail("Entry {$name} item {$index} lies outside the blob.");
                }
                $previousEnd = $end;
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['manifest' => 'The manifest is not JSON.']);
        }

        return is_array($manifest) ? $manifest : throw new ApiException(ErrorCode::ValidationFailed, details: ['manifest' => 'The manifest is not an object.']);
    }

    private static function sha(string $sha256): string
    {
        $sha256 = strtolower(trim($sha256));

        return preg_match('/^[a-f0-9]{64}$/', $sha256) === 1
            ? $sha256
            : throw new ApiException(ErrorCode::ValidationFailed, details: ['sha256' => 'X-Core-Sha256 must be 64 hex digits.']);
    }

    private static function blobKey(string $sha256): string
    {
        return 'delta-core-blob:'.$sha256;
    }

    private static function pendingKey(PipelineJob $job, string $sha256): string
    {
        return 'delta-core-manifest:'.$job->public_id.':'.$sha256;
    }
}
