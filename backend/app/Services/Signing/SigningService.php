<?php

namespace App\Services\Signing;

use App\Enums\ErrorCode;
use App\Enums\PipelineJobStatus;
use App\Enums\SignedBuildStatus;
use App\Exceptions\ApiException;
use App\Jobs\PrepareSigningJob;
use App\Jobs\VerifySignatureJob;
use App\Models\AppArtifact;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Models\SignedBuild;
use App\Services\Audit\Actor;
use App\Services\Installations\InstallationService;
use App\Services\Pipeline\PipelineJobService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Per-device signing (IMPLEMENTATION_PLAN §5.6, P6-BE-02/03):
 * PrepareSigningJob provisions the profile, a runner leases SignArtifactJob
 * and re-signs, VerifySignatureJob checks the result before it becomes
 * DELIVERABLE.
 */
class SigningService
{
    public const RUNNER_JOB_TYPE = 'SignArtifactJob';

    public const LEASE_SECONDS = 600;

    /** Builds that are either usable or on their way to being usable. */
    private const LIVE = [
        SignedBuildStatus::SigningPending, SignedBuildStatus::Signing, SignedBuildStatus::Signed,
        SignedBuildStatus::SignatureVerified, SignedBuildStatus::Deliverable,
    ];

    public function __construct(
        private readonly StateMachine $states,
        private readonly PipelineJobService $jobs,
        private readonly ProfileProvisioner $profiles,
    ) {}

    /**
     * Reuses a live build for (artifact, device), or starts a new one.
     */
    public function requestBuild(AppArtifact $artifact, Device $device): SignedBuild
    {
        $existing = SignedBuild::query()
            ->where(['artifact_id' => $artifact->id, 'device_id' => $device->id])
            ->whereIn('status', array_map(fn (SignedBuildStatus $status) => $status->value, self::LIVE))
            ->latest('id')
            ->lockForUpdate()
            ->get()
            ->first(fn (SignedBuild $build) => $build->status !== SignedBuildStatus::Deliverable || $build->isDeliverable());
        if ($existing !== null) {
            return $existing;
        }

        $build = SignedBuild::create(['artifact_id' => $artifact->id, 'device_id' => $device->id]);
        $job = $this->jobs->create(PrepareSigningJob::TYPE, 'prepare-signing:'.$build->public_id, $build, [
            'signed_build_id' => $build->public_id,
            'artifact_id' => $artifact->public_id,
            'device_id' => $device->public_id,
        ], Actor::system('signing'));
        PrepareSigningJob::dispatch($job->id)->afterCommit();

        return $build;
    }

    /**
     * Step 1 (in the queue): make sure a profile exists, then hand the build to a runner.
     */
    public function prepare(SignedBuild $build): string
    {
        if ($build->status !== SignedBuildStatus::SigningPending) {
            return 'SKIPPED_'.$build->status->value;
        }

        try {
            $profile = $this->profiles->ensure($build->artifact, $build->device);
        } catch (SigningUnavailable $unavailable) {
            $this->failBuild($build, $unavailable->reason, $unavailable->getMessage());

            return $unavailable->reason;
        }

        $build->forceFill(['signing_profile_id' => $profile->id, 'certificate_id' => $profile->certificate_id])->save();
        $this->jobs->create(
            self::RUNNER_JOB_TYPE,
            sprintf('sign:%s:%s:%s', $build->artifact->public_id, $build->device->public_id, $profile->uuid),
            $build,
            ['signed_build_id' => $build->public_id],
            Actor::system('signing'),
        );

        return 'QUEUED_FOR_RUNNER';
    }

    /**
     * Leases the oldest queued signing job this runner can sign. Returns the
     * job description the runner needs, or null when there is no work.
     *
     * @return array<string, mixed>|null
     */
    public function lease(Runner $runner): ?array
    {
        $identities = array_map('strtoupper', array_column($runner->identities ?? [], 'sha1'));

        return DB::transaction(function () use ($runner, $identities) {
            $candidates = PipelineJob::query()
                ->where('type', self::RUNNER_JOB_TYPE)
                ->where('status', PipelineJobStatus::Queued->value)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit(10)
                ->lock('for update skip locked')
                ->get();

            foreach ($candidates as $job) {
                $build = $job->subject;
                if (! $build instanceof SignedBuild || $build->status !== SignedBuildStatus::SigningPending) {
                    $this->states->transition($job, PipelineJobStatus::Cancelled, 'Build is no longer waiting for signing.', Actor::worker($runner->key_id));

                    continue;
                }

                $certificate = $build->certificate;
                if ($certificate === null || ! in_array(strtoupper($certificate->sha1_fingerprint), $identities, true)) {
                    continue; // Another runner holds this certificate.
                }

                $this->jobs->lease($job, $runner->key_id, self::LEASE_SECONDS);
                $this->states->transition($build, SignedBuildStatus::Signing, actor: Actor::worker($runner->key_id));

                return $this->describe($job, $build, $certificate);
            }

            return null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PipelineJob $job, SignedBuild $build, Certificate $certificate): array
    {
        $artifact = $build->artifact;
        $profile = $build->profile ?? throw new RuntimeException('Leased build has no profile.');

        return [
            'job_id' => $job->public_id,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601ZuluString(),
            'signed_build_id' => $build->public_id,
            'bundle_identifier' => $artifact->bundle_identifier,
            'team_identifier' => $profile->team->apple_team_id,
            'certificate_sha1' => strtoupper($certificate->sha1_fingerprint),
            'profile' => ['uuid' => $profile->uuid, 'content' => $profile->content_encrypted],
            'source' => [
                'sha256' => $artifact->sha256,
                'size_bytes' => $artifact->size_bytes,
                'path' => "/api/worker/v1/jobs/{$job->public_id}/source",
            ],
            'upload_path' => "/api/worker/v1/jobs/{$job->public_id}/artifact",
            'result_path' => "/api/worker/v1/jobs/{$job->public_id}/result",
        ];
    }

    public function assertOwner(PipelineJob $job, Runner $runner): SignedBuild
    {
        $build = $job->subject;
        if ($job->type !== self::RUNNER_JOB_TYPE || $job->lease_owner !== $runner->key_id
            || $job->status !== PipelineJobStatus::Running || ! $build instanceof SignedBuild) {
            throw new ApiException(ErrorCode::Conflict, 'This runner does not hold the lease.', ['job_id' => $job->public_id]);
        }

        return $build;
    }

    /**
     * Stores the re-signed IPA streamed by the runner and returns its hash.
     *
     * @param  resource  $body
     * @return array{sha256: string, size_bytes: int}
     */
    public function storeSignedFile(PipelineJob $job, Runner $runner, $body): array
    {
        $build = $this->assertOwner($job, $runner);
        $path = "signed/{$build->public_id}.ipa";

        $context = hash_init('sha256');
        $size = 0;
        $temporary = tmpfile() ?: throw new RuntimeException('No temporary file available.');
        while (! feof($body)) {
            $chunk = fread($body, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($context, $chunk);
            $size += strlen($chunk);
            fwrite($temporary, $chunk);
        }
        rewind($temporary);
        Storage::disk('artifacts')->put($path, $temporary);
        fclose($temporary);

        $sha256 = hash_final($context);
        $build->forceFill(['storage_path' => $path, 'sha256' => $sha256, 'size_bytes' => $size])->save();
        $this->jobs->extendLease($job, self::LEASE_SECONDS);

        return ['sha256' => $sha256, 'size_bytes' => $size];
    }

    /**
     * @param  array{status: string, sha256?: string|null, error_code?: string|null, error_message?: string|null, report?: array<string, mixed>|null}  $result
     */
    public function complete(PipelineJob $job, Runner $runner, array $result): SignedBuild
    {
        $build = $this->assertOwner($job, $runner);
        $attempt = $this->jobs->currentAttempt($job) ?? throw new RuntimeException('Leased job has no attempt.');
        $actor = Actor::worker($runner->key_id);

        if ($result['status'] === 'succeeded') {
            if ($build->storage_path === null || $build->sha256 === null || ! hash_equals($build->sha256, strtolower((string) ($result['sha256'] ?? '')))) {
                throw new ApiException(ErrorCode::UploadCorrupt, 'The uploaded build does not match the reported hash.');
            }

            DB::transaction(function () use ($job, $attempt, $build, $result, $actor) {
                $this->states->transition($build, SignedBuildStatus::Signed, actor: $actor, extra: [
                    'signed_at' => now(),
                    'signing_report' => $result['report'] ?? null,
                ]);
                $this->jobs->succeed($job, $attempt, 'SIGNED');
                $verify = $this->jobs->create(VerifySignatureJob::TYPE, 'verify:'.$build->public_id.':'.$build->sha256, $build, [
                    'signed_build_id' => $build->public_id,
                ], $actor);
                VerifySignatureJob::dispatch($verify->id)->afterCommit();
            });

            return $build;
        }

        $code = mb_substr((string) ($result['error_code'] ?? 'SIGNING_FAILED'), 0, 64);
        DB::transaction(function () use ($job, $attempt, $build, $result, $code, $actor) {
            $retryable = $this->jobs->fail($job, $attempt, new RuntimeException($code.': '.($result['error_message'] ?? '')));
            if ($retryable) {
                $this->states->transition($job, PipelineJobStatus::Queued, 'Runner retry.', $actor, extra: [
                    'available_at' => now()->addMinutes(2 ** min($job->attempt, 4)),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);
                $this->states->transition($build, SignedBuildStatus::SigningPending, $code, $actor);
            } else {
                $this->failBuild($build, $code, (string) ($result['error_message'] ?? 'Signing failed.'));
            }
        });

        return $build;
    }

    /**
     * Returns expired runner leases to the queue and their builds to SIGNING_PENDING.
     */
    public function recoverExpiredLeases(): int
    {
        $recovered = $this->jobs->recoverExpiredLeases();
        foreach ($recovered as $job) {
            $build = $job->subject;
            if ($build instanceof SignedBuild && $build->status === SignedBuildStatus::Signing) {
                $this->states->transition($build, SignedBuildStatus::SigningPending, 'Runner lease expired.', Actor::system('signing'));
            }
        }

        return count($recovered);
    }

    public function failBuild(SignedBuild $build, string $reason, string $message): void
    {
        $to = in_array($build->status, [SignedBuildStatus::Signed], true) ? SignedBuildStatus::ValidationFailed : SignedBuildStatus::SigningFailed;
        $this->states->transition($build, $to, $message, Actor::system('signing'), extra: ['status_reason' => $reason]);
        app(InstallationService::class)->buildFailed($build, $reason);
    }
}
