<?php

namespace App\Services\Signing;

use App\Enums\SignedBuildStatus;
use App\Models\Device;
use App\Models\SignedBuild;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Artifacts\LocalArtifactFile;
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

        $cached = app(ArtifactFileCache::class)->get($disk, $build->storage_path, (string) $build->sha256, (int) $build->size_bytes);
        if ($cached !== null) {
            return $this->checkFile($build, $cached);
        }

        $file = LocalArtifactFile::open($disk, $build->storage_path);
        try {
            return $this->checkFile($build, $file->path);
        } finally {
            $file->release();
        }
    }

    private function checkFile(SignedBuild $build, string $path): ?string
    {
        if (! hash_equals((string) $build->sha256, (string) hash_file('sha256', $path))) {
            return 'SIGNED_FILE_HASH_MISMATCH';
        }

        $result = $this->inspector->inspect($path);
        if (! $result->passed()) {
            return 'SIGNED_FILE_'.($result->failureCode ?? 'INVALID');
        }

        $artifact = $build->artifact;
        $bundleIdentifier = $artifact->signingBundleIdentifier();
        if ($result->bundleValue('bundle_identifier') !== $bundleIdentifier) {
            return 'BUNDLE_ID_CHANGED';
        }
        // Every extension carries the ID it was provisioned for, and nothing was added.
        $extensions = array_column($artifact->signingExtensions(), 'bundle_identifier');
        $signed = array_values(array_map(
            fn (array $item) => $item['bundle_identifier'] ?? null,
            array_filter($result->report['nested_bundles'] ?? [], fn (array $item) => ($item['type'] ?? null) === 'extension'),
        ));
        sort($extensions);
        sort($signed);
        if ($signed !== $extensions) {
            return 'EXTENSION_ID_CHANGED';
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

        // Compare UDIDs in memory only; they are never written anywhere. A shared build must list
        // every device its profile was made for (UDIDs removed by retention cannot be checked).
        $listed = array_map(fn (string $device) => UdidHasher::normalize($device), $embedded['devices']);
        $devices = $build->isShared()
            ? Device::query()->whereKey($expected->device_ids ?? [])->get()
            : collect([$build->device]);
        if ($build->isShared() && count($listed) < count($expected->device_ids ?? [])) {
            return 'DEVICE_NOT_IN_PROFILE';
        }
        foreach ($devices as $device) {
            $udid = $device?->udid_encrypted === null ? null : UdidHasher::normalize((string) $device->udid_encrypted);
            if (($udid === null && ! $build->isShared()) || ($udid !== null && ! in_array($udid, $listed, true))) {
                return 'DEVICE_NOT_IN_PROFILE';
            }
        }

        $applicationId = $result->report['entitlements']['application-identifier'] ?? null;
        if ($applicationId !== null && $applicationId !== $expected->team->apple_team_id.'.'.$bundleIdentifier) {
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
