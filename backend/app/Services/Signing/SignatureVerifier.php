<?php

namespace App\Services\Signing;

use App\Enums\SignedBuildStatus;
use App\Models\SignedBuild;
use App\Services\Audit\Actor;
use App\Services\Devices\UdidHasher;
use App\Services\Inspection\IpaInspector;
use App\Services\Installations\InstallationService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * VerifySignatureJob (IMPLEMENTATION_PLAN §5.6): the server does not trust
 * the runner's word. It re-hashes the uploaded file, re-runs the IPA safety
 * checks, and confirms the bundle ID is unchanged and the embedded profile is
 * the one provisioned for this device and team.
 */
class SignatureVerifier
{
    public function __construct(
        private readonly StateMachine $states,
        private readonly IpaInspector $inspector,
        private readonly SigningService $signing,
    ) {}

    public function verify(SignedBuild $build): string
    {
        if ($build->status !== SignedBuildStatus::Signed) {
            return 'SKIPPED_'.$build->status->value;
        }

        $failure = $this->check($build);
        if ($failure !== null) {
            $this->signing->failBuild($build, $failure, 'Signature verification failed: '.$failure);

            return $failure;
        }

        $profile = $build->profile;
        $certificate = $build->certificate;
        $expiries = array_filter([$profile?->expires_at, $certificate?->expires_at]);
        $expiresAt = $expiries === [] ? null : min($expiries);

        DB::transaction(function () use ($build, $expiresAt) {
            $actor = Actor::system('verifier');
            $this->states->transition($build, SignedBuildStatus::SignatureVerified, actor: $actor, extra: ['verified_at' => now()]);
            $this->states->transition($build, SignedBuildStatus::Deliverable, actor: $actor, extra: ['expires_at' => $expiresAt]);
            app(InstallationService::class)->buildReady($build);
        });

        return 'DELIVERABLE';
    }

    private function check(SignedBuild $build): ?string
    {
        $disk = Storage::disk('artifacts');
        if ($build->storage_path === null || ! $disk->exists($build->storage_path)) {
            return 'SIGNED_FILE_MISSING';
        }

        $path = $disk->path($build->storage_path);
        if (! hash_equals((string) $build->sha256, (string) hash_file('sha256', $path))) {
            return 'SIGNED_FILE_HASH_MISMATCH';
        }

        $result = $this->inspector->inspect($path);
        if (! $result->passed()) {
            return 'SIGNED_FILE_'.($result->failureCode ?? 'INVALID');
        }

        $artifact = $build->artifact;
        if ($result->bundleValue('bundle_identifier') !== $artifact->bundle_identifier) {
            return 'BUNDLE_ID_CHANGED';
        }
        if ($result->bundleValue('version') !== $artifact->version || $result->bundleValue('build_number') !== $artifact->build_number) {
            return 'VERSION_CHANGED';
        }

        $embedded = $this->embeddedProfile($path, (string) $result->bundleValue('path'));
        $expected = $build->profile;
        if ($embedded === null || $expected === null) {
            return 'PROFILE_MISSING';
        }
        if ($embedded['uuid'] !== $expected->uuid) {
            return 'PROFILE_MISMATCH';
        }
        if ($embedded['team_identifier'] !== $expected->team->apple_team_id) {
            return 'TEAM_MISMATCH';
        }

        // Compare UDIDs in memory only; they are never written anywhere.
        $udid = UdidHasher::normalize((string) $build->device->udid_encrypted);
        $listed = array_map(fn (string $device) => UdidHasher::normalize($device), $embedded['devices']);
        if (! in_array($udid, $listed, true)) {
            return 'DEVICE_NOT_IN_PROFILE';
        }

        $applicationId = $result->report['entitlements']['application-identifier'] ?? null;
        if ($applicationId !== null && $applicationId !== $expected->team->apple_team_id.'.'.$artifact->bundle_identifier) {
            return 'ENTITLEMENTS_MISMATCH';
        }

        return null;
    }

    /**
     * @return array{uuid: string|null, team_identifier: string|null, devices: list<string>, expires_at: int|null, entitlements: array<string, mixed>}|null
     */
    private function embeddedProfile(string $path, string $appPath): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $stat = $zip->statName($appPath.'/embedded.mobileprovision');
            if ($stat === false || $stat['size'] > 4 * 1024 * 1024) {
                return null;
            }
            $bytes = $zip->getFromName($appPath.'/embedded.mobileprovision');

            return is_string($bytes) ? ProvisioningProfile::parse($bytes) : null;
        } finally {
            $zip->close();
        }
    }
}
