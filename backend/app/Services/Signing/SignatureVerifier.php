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
use App\Services\Signing\Delta\DeltaComposer;
use App\Services\Signing\Delta\DeltaCoreLocator;
use App\Services\Signing\Delta\DeltaException;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
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
        $disk = Storage::disk('signed');
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
        // A delta-v1 pack is inspected as it is: it holds the Info.plists, profiles and binaries re-signing
        // changed. A delta-v2 pack holds binaries as segments, so its changed entries are composed first.
        $inspect = null;
        try {
            if ($build->isDelta() && ($failure = $this->checkDelta($build, $path, $inspect)) !== null) {
                return $failure;
            }

            return $this->checkContents($build, $inspect ?? $path);
        } finally {
            if ($inspect !== null) {
                @unlink($inspect);
            }
        }
    }

    private function checkContents(SignedBuild $build, string $path): ?string
    {
        // A pack comes from an artifact that was scanned when it was inspected.
        $result = $this->inspector->inspect($path, scan: ! $build->isDelta());
        if (! $result->passed()) {
            return 'SIGNED_FILE_'.($result->failureCode ?? 'INVALID');
        }

        $artifact = $build->artifact;
        $team = $build->profile?->team;
        $bundleIdentifier = $artifact->signingBundleIdentifier($team);
        if ($result->bundleValue('bundle_identifier') !== $bundleIdentifier) {
            return 'BUNDLE_ID_CHANGED';
        }
        // Every extension carries the ID it was provisioned for, and nothing was added.
        $extensions = array_column($artifact->signingExtensions($team), 'bundle_identifier');
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
     * The pack must compose, with the artifact's original, into an IPA of a known size. The IPA itself
     * is not read: the runner already verified its signatures, and a pack-only inspection follows.
     * A delta-v2 pack's segmented entries are inflated in full and checked against their size and
     * CRC-32; $inspect then receives a temporary ZIP of the changed entries alone, for the inspection.
     */
    private function checkDelta(SignedBuild $build, string $packPath, ?string &$inspect = null): ?string
    {
        $artifact = $build->artifact;
        $disk = Storage::disk($artifact->storage_disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter || ! $disk->exists($artifact->storage_path)) {
            return 'DELTA_BASE_MISSING';
        }
        $basePath = $disk->path($artifact->storage_path);
        try {
            $coreSha256 = DeltaComposer::requiredCore($packPath);
            $corePath = $coreSha256 === null ? null : app(DeltaCoreLocator::class)->localPath($coreSha256);
            if ($coreSha256 !== null && ($corePath === null || ! is_file($corePath))) {
                return 'DELTA_CORE_MISSING';
            }
            $plan = DeltaComposer::plan($basePath, $packPath, checkHeaders: true, corePath: $corePath);
            $plan->checkSegmented($packPath, $corePath);
        } catch (DeltaException) {
            return 'DELTA_INVALID';
        }
        if (! hash_equals(strtolower((string) $artifact->sha256), $plan->baseSha256)) {
            return 'DELTA_BASE_MISMATCH';
        }
        if ($plan->version === 2) {
            $inspect = $this->composeChanged($basePath, $packPath, $corePath);
            if ($inspect === null) {
                return 'DELTA_INVALID';
            }
        }
        $build->forceFill(['delivered_size' => $plan->size])->save();

        return null;
    }

    /** A temporary ZIP of the entries a delta-v2 pack changes (whole or segmented), or null on failure. */
    private function composeChanged(string $basePath, string $packPath, ?string $corePath): ?string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'delta-changed');
        $out = $temporary === false ? false : fopen($temporary, 'wb');
        if ($out === false) {
            return null;
        }
        try {
            $plan = DeltaComposer::plan($basePath, $packPath, corePath: $corePath, changedOnly: true);
            $plan->stream($basePath, $packPath, 0, $plan->size - 1, function (string $chunk) use ($out) {
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new DeltaException('Cannot write the changed entries.');
                }
            }, fn () => true, $corePath);
            fclose($out);
            $out = null;

            return $temporary;
        } catch (DeltaException) {
            @unlink($temporary);

            return null;
        } finally {
            if ($out !== null) {
                fclose($out);
            }
        }
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
