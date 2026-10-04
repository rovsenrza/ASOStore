<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Jobs\InspectArtifactJob;
use App\Models\AppArtifact;
use App\Models\UploadSession;
use App\Services\Audit\AuditService;
use App\Services\Pipeline\PipelineJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChunkedUploadService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PipelineJobService $jobs,
    ) {}

    /**
     * @return array{number: int, size_bytes: int, sha256: string, already_received: bool}
     */
    public function storeChunk(UploadSession $upload, int $number, string $contents, ?string $declaredSha256): array
    {
        $upload = UploadSession::query()->findOrFail($upload->id);
        $this->assertOpen($upload);

        if ($number < 0 || $number >= $upload->chunk_count) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['chunk' => 'Неверный номер части файла.']);
        }

        $expectedSize = $number === $upload->chunk_count - 1
            ? $upload->expected_size - ($number * $upload->chunk_size)
            : $upload->chunk_size;
        $size = strlen($contents);
        $sha256 = hash('sha256', $contents);

        if ($size !== $expectedSize || ($declaredSha256 !== null && ! hash_equals(strtolower($declaredSha256), $sha256))) {
            throw new ApiException(ErrorCode::UploadCorrupt, details: [
                'chunk' => $number,
                'expected_size' => $expectedSize,
                'actual_size' => $size,
            ]);
        }

        $existing = $upload->chunks()->where('number', $number)->first();
        if ($existing !== null) {
            if ($existing->size_bytes === $size && hash_equals($existing->sha256, $sha256)) {
                return ['number' => $number, 'size_bytes' => $size, 'sha256' => $sha256, 'already_received' => true];
            }

            throw new ApiException(ErrorCode::Conflict, 'Эта часть уже загружена с другим содержимым.');
        }

        $path = "uploads/{$upload->public_id}/chunks/{$number}.part";
        Storage::disk('artifacts')->put($path, $contents);

        try {
            $upload->chunks()->create([
                'number' => $number,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'storage_path' => $path,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('artifacts')->delete($path);
            throw $exception;
        }

        return ['number' => $number, 'size_bytes' => $size, 'sha256' => $sha256, 'already_received' => false];
    }

    public function complete(UploadSession $upload): AppArtifact
    {
        $upload = DB::transaction(function () use ($upload) {
            $locked = UploadSession::query()->lockForUpdate()->findOrFail($upload->id);

            if ($locked->status === 'COMPLETED' && $locked->artifact_id !== null) {
                return $locked;
            }

            $this->assertOpen($locked);
            $chunks = $locked->chunks()->orderBy('number')->get();
            $received = $chunks->pluck('number')->all();
            $expected = range(0, $locked->chunk_count - 1);

            if ($received !== $expected || $chunks->sum('size_bytes') !== $locked->expected_size) {
                throw new ApiException(ErrorCode::UploadIncomplete, details: [
                    'received_chunks' => $received,
                    'missing_chunks' => array_values(array_diff($expected, $received)),
                ]);
            }

            $locked->update(['status' => 'ASSEMBLING']);

            return $locked->setRelation('chunks', $chunks);
        });

        if ($upload->status === 'COMPLETED') {
            return $upload->artifact()->firstOrFail();
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            $this->fail($upload, 'TEMPORARY_FILE_UNAVAILABLE');
            throw new ApiException(ErrorCode::ServiceUnavailable);
        }

        $hash = hash_init('sha256');
        $actualSize = 0;

        try {
            foreach ($upload->chunks as $chunk) {
                $stream = Storage::disk('artifacts')->readStream($chunk->storage_path);
                if ($stream === null) {
                    throw new ApiException(ErrorCode::UploadIncomplete, details: ['missing_chunk' => $chunk->number]);
                }

                while (! feof($stream)) {
                    $buffer = fread($stream, 1024 * 1024);
                    if ($buffer === false) {
                        fclose($stream);
                        throw new ApiException(ErrorCode::UploadCorrupt);
                    }
                    if ($buffer === '') {
                        continue;
                    }
                    hash_update($hash, $buffer);
                    $actualSize += strlen($buffer);
                    fwrite($temporary, $buffer);
                }
                fclose($stream);
            }

            $sha256 = hash_final($hash);
            if ($actualSize !== $upload->expected_size
                || ($upload->expected_sha256 !== null && ! hash_equals($upload->expected_sha256, $sha256))) {
                $this->fail($upload, ErrorCode::UploadCorrupt->value);
                throw new ApiException(ErrorCode::UploadCorrupt, details: [
                    'expected_size' => $upload->expected_size,
                    'actual_size' => $actualSize,
                    'expected_sha256' => $upload->expected_sha256,
                    'actual_sha256' => $sha256,
                ]);
            }

            // A file that was rejected, or failed inspection or provenance, may come again once
            // the reason is fixed (e.g. the team approval); any other copy is a duplicate.
            // A customer's import is private to them, so the same file imported by someone else
            // (or present in the catalog) is not a duplicate; the stored original is shared by path.
            // Likewise a customer's private import never blocks an operator uploading the same file.
            $duplicate = $upload->source_type === SourceType::UserImport ? null : AppArtifact::query()->where('sha256', $sha256)
                ->where('source_type', '!=', SourceType::UserImport->value)
                ->whereNotIn('status', [ArtifactStatus::Rejected->value, ArtifactStatus::InspectionFailed->value, ArtifactStatus::ProvenanceFailed->value])
                ->first();
            if ($duplicate !== null) {
                $this->fail($upload, ErrorCode::DuplicateArtifact->value);
                Storage::disk('artifacts')->deleteDirectory("uploads/{$upload->public_id}");
                throw new ApiException(ErrorCode::DuplicateArtifact, details: ['artifact_id' => $duplicate->public_id]);
            }

            $path = 'originals/'.substr($sha256, 0, 2).'/'.$sha256.'.ipa';
            rewind($temporary);
            Storage::disk('artifacts')->put($path, $temporary);

            $artifact = DB::transaction(function () use ($upload, $sha256, $actualSize, $path) {
                $artifact = AppArtifact::create([
                    'app_id' => $upload->app_id,
                    'app_version_id' => $upload->app_version_id,
                    'sha256' => $sha256,
                    'size_bytes' => $actualSize,
                    'storage_disk' => 'artifacts',
                    'storage_path' => $path,
                    'original_filename' => $upload->original_filename,
                    'source_type' => $upload->source_type,
                    'uploaded_by' => $upload->uploaded_by,
                    'declaration_version' => $upload->declaration_version,
                    'declaration_accepted_at' => $upload->declaration_accepted_at,
                    'declaration_ip' => $upload->declaration_ip,
                    'status' => ArtifactStatus::Uploaded,
                ]);

                $upload->update(['status' => 'COMPLETED', 'artifact_id' => $artifact->id]);
                $this->audit->record('artifact.uploaded', $artifact, after: [
                    'sha256' => $sha256,
                    'size_bytes' => $actualSize,
                    'upload_id' => $upload->public_id,
                ]);

                $job = $this->jobs->create(InspectArtifactJob::TYPE, InspectArtifactJob::idempotencyKey($artifact), $artifact, [
                    'artifact_id' => $artifact->public_id,
                ]);
                InspectArtifactJob::dispatch($job->id)->afterCommit();

                return $artifact;
            });

            Storage::disk('artifacts')->deleteDirectory("uploads/{$upload->public_id}");

            return $artifact;
        } catch (ApiException $exception) {
            if ($upload->fresh()->status === 'ASSEMBLING') {
                $this->fail($upload, $exception->errorCode->value);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->fail($upload, 'ASSEMBLY_FAILED');
            throw $exception;
        } finally {
            fclose($temporary);
        }
    }

    private function assertOpen(UploadSession $upload): void
    {
        if ($upload->expires_at->isPast()) {
            throw new ApiException(ErrorCode::Conflict, 'Срок действия сессии загрузки истёк.', status: 410);
        }
        if ($upload->status !== 'OPEN') {
            throw new ApiException(ErrorCode::Conflict, 'Сессия загрузки уже закрыта.');
        }
    }

    private function fail(UploadSession $upload, string $reason): void
    {
        $upload->update(['status' => 'FAILED', 'failure_reason' => $reason]);
    }
}
