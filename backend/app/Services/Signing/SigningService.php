<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Enums\ErrorCode;
use App\Enums\PipelineJobStatus;
use App\Enums\SignedBuildStatus;
use App\Exceptions\ApiException;
use App\Jobs\PrepareSigningJob;
use App\Jobs\VerifySignatureJob;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Models\SignedBuild;
use App\Models\User;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Artifacts\LocalArtifactFile;
use App\Services\Audit\Actor;
use App\Services\Installations\InstallationService;
use App\Services\Pipeline\PipelineJobService;
use App\Services\Storefront\ClaimService;
use App\StateMachines\StateMachine;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Signing (IMPLEMENTATION_PLAN §5.6, P6-BE-02/03): PrepareSigningJob provisions the
 * profile, a runner leases SignArtifactJob and re-signs, VerifySignatureJob checks the
 * result before it becomes DELIVERABLE.
 *
 * Builds are shared by an Apple team (storefront.signing.shared_builds): one build of an
 * artifact, signed with a profile listing the team's devices, serves each of them, so a
 * later install of the same app starts at once. The storefront app stays per device: it
 * carries the customer's one-time login code.
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
     * Reuses a live build that serves the device (its own, or one its team shares), preferring
     * one that is ready; otherwise starts a new one, shared by the device's team.
     */
    public function requestBuild(AppArtifact $artifact, Device $device, int $priority = 0, ?User $bootstrapUser = null): SignedBuild
    {
        return DB::transaction(function () use ($artifact, $device, $priority, $bootstrapUser) {
            // The storefront app embeds a one-time login code per build, so it is always signed
            // fresh (never a reused build with a code already redeemed on an earlier install).
            $bootstrap = $bootstrapUser !== null && $artifact->app->is_storefront;

            // Also locks the first build: locking an empty build query cannot prevent duplicate inserts.
            Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $teamId = $this->sharedTeamFor($artifact, $device, $bootstrap);
            if ($teamId !== null) {
                // Devices of one team ask for the same shared build: one maker per team.
                AppleTeam::query()->whereKey($teamId)->lockForUpdate()->firstOrFail();
            }
            if (! $bootstrap) {
                $candidates = SignedBuild::query()
                    ->where('artifact_id', $artifact->id)
                    ->where(fn ($query) => $query->where('device_id', $device->id)
                        ->when($teamId !== null, fn ($query) => $query->orWhere(fn ($query) => $query->whereNull('device_id')->where('apple_team_id', $teamId))))
                    ->whereIn('status', array_map(fn (SignedBuildStatus $status) => $status->value, self::LIVE))
                    ->with(['profile', 'certificate'])
                    ->latest('id')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (SignedBuild $build) => ($build->status !== SignedBuildStatus::Deliverable || $build->isDeliverable()) && $build->serves($device));
                $existing = $candidates->first(fn (SignedBuild $build) => $build->status === SignedBuildStatus::Deliverable) ?? $candidates->first();
                if ($existing !== null) {
                    if ($priority === 0) {
                        $this->promote($existing);
                    }
                    // Keeps a build that is still wanted from being reclaimed as idle (StorageJanitor).
                    $existing->forceFill(['last_used_at' => now()])->save();

                    return $existing;
                }
            }

            $attributes = ['artifact_id' => $artifact->id, 'last_used_at' => now()]
                + ($teamId !== null ? ['device_id' => null, 'apple_team_id' => $teamId] : ['device_id' => $device->id]);
            if ($bootstrap) {
                $attributes['bootstrap_claim_encrypted'] = app(ClaimService::class)
                    ->mint($bootstrapUser, $device, (int) config('storefront.claims.bootstrap_ttl_minutes', 60));
            }
            $build = SignedBuild::create($attributes);
            $job = $this->jobs->create(PrepareSigningJob::TYPE, 'prepare-signing:'.$build->public_id, $build, [
                'signed_build_id' => $build->public_id,
                'artifact_id' => $artifact->public_id,
                'device_id' => $device->public_id,
                'shared' => $teamId !== null,
                'priority' => $priority,
            ], Actor::system('signing'));
            PrepareSigningJob::dispatch($job->id)->onQueue($priority > 0 ? 'background' : PrepareSigningJob::QUEUE)->afterCommit();

            return $build;
        });
    }

    /**
     * The team whose shared build this device should use, or null for a build of its own:
     * the storefront app (one-time login code), or sharing switched off, or no eligible team
     * (preparation then fails with DEVICE_NOT_ELIGIBLE, as before).
     */
    private function sharedTeamFor(AppArtifact $artifact, Device $device, bool $bootstrap): ?int
    {
        if ($bootstrap || $artifact->app?->is_storefront || ! config('storefront.signing.shared_builds', true)) {
            return null;
        }
        $registration = $device->latestRegistration()->first();

        return $registration?->status === DeviceRegistrationStatus::Eligible && $registration->apple_device_id !== null
            ? $registration->apple_team_id
            : null;
    }

    /**
     * A customer now wants a build that started as a speculative one: it goes to the front
     * of the runner queue, and a preparation still waiting on the slow background queue is
     * also put on the customer queue (whichever worker starts it first does the work).
     */
    private function promote(SignedBuild $build): void
    {
        $jobs = PipelineJob::query()->where('subject_type', $build->getMorphClass())->where('subject_id', $build->id)
            ->whereIn('type', [PrepareSigningJob::TYPE, self::RUNNER_JOB_TYPE])
            ->whereIn('status', [PipelineJobStatus::Queued->value, PipelineJobStatus::Running->value, PipelineJobStatus::FailedRetryable->value])->get();
        foreach ($jobs as $job) {
            if (($job->payload['priority'] ?? 0) === 0) {
                continue;
            }
            $job->forceFill(['payload' => array_replace($job->payload, ['priority' => 0])])->save();
            if ($job->type === PrepareSigningJob::TYPE && $job->status !== PipelineJobStatus::Running) {
                PrepareSigningJob::dispatch($job->id)->onQueue(PrepareSigningJob::QUEUE)->afterCommit();
            }
        }
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
            $profile = $build->isShared()
                ? $this->profiles->ensureShared($build->artifact, $build->team)
                : $this->profiles->ensure($build->artifact, $build->device);
        } catch (SigningUnavailable $unavailable) {
            $this->failBuild($build, $unavailable->reason, $unavailable->getMessage());

            return $unavailable->reason;
        }

        DB::transaction(function () use ($build, $profile) {
            $build->isShared()
                ? AppleTeam::query()->whereKey($build->apple_team_id)->lockForUpdate()->firstOrFail()
                : Device::query()->whereKey($build->device_id)->lockForUpdate()->firstOrFail();
            $priority = PipelineJob::query()->where('subject_type', $build->getMorphClass())->where('subject_id', $build->id)
                ->where('type', PrepareSigningJob::TYPE)->value('payload')['priority'] ?? 0;
            $build->forceFill(['signing_profile_id' => $profile->id, 'certificate_id' => $profile->certificate_id])->save();
            $this->jobs->create(
                self::RUNNER_JOB_TYPE,
                'sign:'.$build->public_id,
                $build,
                ['signed_build_id' => $build->public_id, 'priority' => $priority],
                Actor::system('signing'),
            );
        });

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
            // The runner's parallel lease loops share this row. A speculative job only starts
            // on an idle runner, so a customer's install always finds a free slot.
            Runner::query()->whereKey($runner->id)->lockForUpdate()->firstOrFail();
            $candidates = PipelineJob::query()
                ->where('type', self::RUNNER_JOB_TYPE)
                ->where('status', PipelineJobStatus::Queued->value)
                ->where('available_at', '<=', now())
                ->orderByRaw("COALESCE(JSON_EXTRACT(payload, '$.priority'), 0)")
                ->orderBy('id')
                ->limit(10)
                ->pluck('id');

            foreach ($candidates as $id) {
                // Lock only the job being considered, so another worker can lease the next one.
                $job = PipelineJob::query()
                    ->with(['subject.certificate', 'subject.artifact.app', 'subject.profile.team'])
                    ->whereKey($id)
                    ->where('status', PipelineJobStatus::Queued->value)
                    ->lock('for update skip locked')
                    ->first();
                if ($job === null) {
                    continue;
                }
                $build = $job->subject;
                if (! $build instanceof SignedBuild || $build->status !== SignedBuildStatus::SigningPending) {
                    $this->states->transition($job, PipelineJobStatus::Cancelled, 'Build is no longer waiting for signing.', Actor::worker($runner->key_id));

                    continue;
                }

                $certificate = $build->certificate;
                if ($certificate === null || ! in_array(strtoupper($certificate->sha1_fingerprint), $identities, true)) {
                    continue; // Another runner holds this certificate.
                }

                if (($job->payload['priority'] ?? 0) > 0 && PipelineJob::query()
                    ->where('type', self::RUNNER_JOB_TYPE)->where('lease_owner', $runner->key_id)
                    ->where('status', PipelineJobStatus::Running->value)->where('lease_expires_at', '>', now())->exists()) {
                    continue;
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
        $source = [
            'sha256' => $artifact->sha256,
            'size_bytes' => $artifact->size_bytes,
            'path' => "/api/worker/v1/jobs/{$job->public_id}/source",
        ];
        $disk = Storage::disk($artifact->storage_disk);
        if ($disk instanceof AwsS3V3Adapter) {
            $source['download_url'] = $disk->temporaryUrl($artifact->storage_path, now()->addMinutes(10));
        }

        $lease = [
            'job_id' => $job->public_id,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601ZuluString(),
            'signed_build_id' => $build->public_id,
            // The runner re-identifies the IPA to these IDs before signing.
            'bundle_identifier' => $artifact->signingBundleIdentifier(),
            'team_identifier' => $profile->team->apple_team_id,
            'certificate_sha1' => strtoupper($certificate->sha1_fingerprint),
            'profile' => ['uuid' => $profile->uuid, 'content' => $profile->content_encrypted],
            'nested' => array_map(fn (array $extension) => [
                'path' => $extension['path'],
                'bundle_identifier' => $extension['bundle_identifier'],
                'profile' => ['uuid' => $extension['profile']->uuid, 'content' => $extension['profile']->content_encrypted],
            ], $this->profiles->extensionProfiles($artifact, $profile)),
            // Launch-compatibility shim for apps that share through an App Group / keychain.
            'inject_dylibs' => app(CompatShim::class)->dylibsFor($artifact),
            'source' => $source,
            'upload_path' => "/api/worker/v1/jobs/{$job->public_id}/artifact",
            'result_path' => "/api/worker/v1/jobs/{$job->public_id}/result",
        ];
        // One-time storefront login code to embed in Info.plist (storefront builds only).
        if ($build->bootstrap_claim_encrypted !== null) {
            $lease['bootstrap_claim'] = $build->bootstrap_claim_encrypted;
        }
        // The vendor's bundle ID, for apps whose servers check it (CompatShim::originalBundleFor).
        if (($original = app(CompatShim::class)->originalBundleFor($artifact)) !== null) {
            $lease['original_bundle_identifier'] = $original;
        }
        // The vendor's team ID, so Info.plist values built from it are re-prefixed with ours.
        if (($team = app(CompatShim::class)->originalTeamFor($artifact, (string) $profile->team->apple_team_id)) !== null) {
            $lease['original_team_identifier'] = $team;
        }

        return $lease;
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
        $temporaryPath = LocalArtifactFile::temporaryPath('signed');
        $temporary = fopen($temporaryPath, 'w+b') ?: throw new RuntimeException('No temporary file available.');
        try {
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
            $sha256 = hash_final($context);
            $disk = Storage::disk('artifacts');
            $disk->put($path, $temporary);
            app(ArtifactFileCache::class)->store($disk, $path, $sha256, $size, $temporary);
        } finally {
            fclose($temporary);
            @unlink($temporaryPath);
        }
        // A retried build may have had an earlier file reclaimed; this one is live again.
        $build->forceFill(['storage_path' => $path, 'sha256' => $sha256, 'size_bytes' => $size, 'purged_at' => null, 'last_used_at' => now()])->save();
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
