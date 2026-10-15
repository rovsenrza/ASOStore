<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Jobs\AssembleUploadJob;
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
                // The row says we already have this chunk, but object storage can silently
                // drop a write (or a part is lost under load); restore the object from this
                // resend so assembly does not fail later for a chunk the client "already sent".
                if (! Storage::disk('artifacts')->exists($existing->storage_path)) {
                    Storage::disk('artifacts')->put($existing->storage_path, $contents);
                }

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

    /**
     * Whether assembling this upload is slow enough that the request must not wait for it: the app
     * gives `complete` 30 seconds and PHP's CPU limit is 30 seconds too, while reading, hashing and
     * storing a multi-gigabyte IPA takes minutes.
     */
    public function assemblesInBackground(UploadSession $upload): bool
    {
        return $upload->expected_size > (int) config('storefront.uploads.background_assembly_bytes', 100 * 1024 * 1024);
    }

    /**
     * Checks that every chunk is there and queues the assembly, so the request can answer at once.
     * Asking again while it runs (the app retries a call it did not hear back from) gets the same
     * answer instead of "session already closed". The caller reads the session's status afterwards:
     * ASSEMBLING while the job works, COMPLETED when the file is stored.
     */
    public function completeInBackground(UploadSession $upload): UploadSession
    {
        $upload = DB::transaction(function () use ($upload) {
            $locked = UploadSession::query()->lockForUpdate()->findOrFail($upload->id);
            if (in_array($locked->status, ['ASSEMBLING', 'COMPLETED'], true)) {
                return $locked;
            }

            return $this->beginAssembly($locked);
        });

        if ($upload->status === 'ASSEMBLING') {
            $job = $this->jobs->create(AssembleUploadJob::TYPE, AssembleUploadJob::idempotencyKey($upload), $upload, ['upload_id' => $upload->public_id]);
            AssembleUploadJob::dispatch($job->id);
        }

        return $upload->refresh();
    }

    /** OPEN to ASSEMBLING once all chunks are stored. Inside a transaction, on a locked row. */
    private function beginAssembly(UploadSession $locked): UploadSession
    {
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
    }

    public function complete(UploadSession $upload): AppArtifact
    {
        $upload = DB::transaction(function () use ($upload) {
            $locked = UploadSession::query()->lockForUpdate()->findOrFail($upload->id);

            if ($locked->status === 'COMPLETED' && $locked->artifact_id !== null) {
                return $locked;
            }

            return $this->beginAssembly($locked);
        });

        if ($upload->status === 'COMPLETED') {
            return $upload->artifact()->firstOrFail();
        }

        return $this->assemble($upload);
    }

    /**
     * Joins the chunks of an ASSEMBLING upload into the stored original and queues its inspection.
     * `$background` is the queued job: it keeps the session ASSEMBLING while the job retries, and
     * ends it as FAILED, with a sentence the app shows, when the file cannot be assembled.
     */
    public function assemble(UploadSession $upload, bool $background = false): AppArtifact
    {
        // Reading, hashing and storing a big file takes more CPU time than the web limit of 30 s.
        @set_time_limit(0);
        if (! $upload->relationLoaded('chunks')) {
            $upload->load(['chunks' => fn ($query) => $query->orderBy('number')]);
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            if (! $background) {
                UploadSession::query()->whereKey($upload->id)->where('status', 'ASSEMBLING')->update(['status' => 'OPEN']);
            }
            throw new ApiException(ErrorCode::ServiceUnavailable);
        }

        $hash = hash_init('sha256');
        $actualSize = 0;

        try {
            foreach ($upload->chunks as $chunk) {
                try {
                    $stream = Storage::disk('artifacts')->readStream($chunk->storage_path);
                } catch (\Throwable $readError) {
                    // Flysystem throws (not returns null) when object storage lost the part.
                    report($readError);
                    $stream = null;
                }
                if ($stream === null) {
                    // Object storage lost this part. Forget just this chunk and reopen the
                    // session so the client re-uploads only it, instead of failing the whole
                    // upload — the DB row otherwise makes the client skip it forever.
                    $chunk->delete();
                    if ($background) {
                        // The app already thinks it is done, so it cannot send the part again.
                        $this->fail($upload, self::BACKGROUND_FAILURE);
                    } else {
                        UploadSession::query()->whereKey($upload->id)->where('status', 'ASSEMBLING')->update(['status' => 'OPEN']);
                    }
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
            $this->storeOriginal($path, $temporary);

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
            // A busy server is not the file's fault: the queued job tries again.
            $retry = $background && $exception->errorCode === ErrorCode::ServiceUnavailable;
            if (! $retry && $upload->fresh()->status === 'ASSEMBLING') {
                $this->fail($upload, $exception->errorCode->value);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            // Storage or database trouble, not a bad file: every chunk is still stored, so the
            // upload goes back to OPEN and completing it again can succeed. In the queued job it
            // stays ASSEMBLING and the job tries again (AssembleUploadJob ends it when it gives up).
            report($exception);
            if ($background) {
                throw $exception;
            }
            UploadSession::query()->whereKey($upload->id)->where('status', 'ASSEMBLING')->update(['status' => 'OPEN']);
            throw new ApiException(ErrorCode::ServiceUnavailable, 'Сервер временно не смог сохранить файл. Повторите попытку.');
        } finally {
            fclose($temporary);
        }
    }

    /**
     * Object storage occasionally fails a large multipart write; one bad part should not cost the
     * customer the whole upload.
     *
     * @param  resource  $file
     */
    private function storeOriginal(string $path, $file): void
    {
        $attempts = 3;
        for ($attempt = 1; ; $attempt++) {
            try {
                rewind($file);
                if (Storage::disk('artifacts')->put($path, $file) === false) {
                    throw new \RuntimeException("Unable to write file at location: {$path}.");
                }

                return;
            } catch (\Throwable $exception) {
                if ($attempt >= $attempts) {
                    throw $exception;
                }
                report($exception);
                usleep($attempt * 500_000);
            }
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

    /** Shown by the app as it is (a reason with a space is a sentence): the upload cannot be repaired from the phone. */
    public const BACKGROUND_FAILURE = 'Сервер не смог собрать файл. Загрузите его ещё раз.';

    public function fail(UploadSession $upload, string $reason): void
    {
        $upload->update(['status' => 'FAILED', 'failure_reason' => $reason]);
    }
}
