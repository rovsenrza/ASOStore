<?php

namespace App\Services\Catalog;

use App\Enums\DeviceRegistrationStatus;
use App\Enums\ErrorCode;
use App\Enums\InstallationStatus;
use App\Enums\InstallStateStatus;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\Installation;
use App\Models\User;
use App\Services\Devices\CurrentDevice;

/**
 * Decides the install CTA for one app and the calling device. Clients must
 * render the CTA from this result only (FULL_PLAN §4.2, §11).
 */
class InstallStateResolver
{
    /**
     * Relations that must be eager-loaded on the app before calling resolve().
     */
    public const REQUIRED_RELATIONS = ['publishedArtifact'];

    /** @var array<int, Device|null> Per-request cache keyed by user ID. */
    private array $devices = [];

    public function __construct(private readonly CurrentDevice $currentDevice) {}

    /**
     * @return array{status: string, reason: string|null, progress: float|null, installation_id: string|null}
     */
    public function resolve(CatalogApp $app, ?User $user): array
    {
        $artifact = $app->publishedArtifact;
        if ($artifact === null) {
            return $this->state(InstallStateStatus::Unavailable, ErrorCode::ArtifactNotInstallable);
        }

        if ($user === null) {
            return $this->state(InstallStateStatus::NotEligible, ErrorCode::Unauthenticated);
        }

        $device = $this->device($user);
        $registration = $device?->latestRegistration;
        if ($registration?->status !== DeviceRegistrationStatus::Eligible) {
            return $this->state(InstallStateStatus::NotEligible, match ($registration?->status) {
                DeviceRegistrationStatus::Enrolled, DeviceRegistrationStatus::ApplePending => ErrorCode::DevicePendingApple,
                DeviceRegistrationStatus::QuotaBlocked => ErrorCode::QuotaExhausted,
                DeviceRegistrationStatus::NoEligibleTeam => ErrorCode::NoEligibleTeam,
                default => ErrorCode::DeviceNotEligible,
            });
        }

        /** @var Device $device */
        $latest = Installation::query()
            ->with('signedBuild')
            ->where(['device_id' => $device->id, 'app_id' => $app->id])
            ->latest('id')
            ->first();
        if ($latest === null) {
            return $this->state(InstallStateStatus::Get);
        }

        $sameBuild = $latest->artifact_id === $artifact->id;

        return match (true) {
            $latest->status === InstallationStatus::Delivered && ! $sameBuild => $this->state(InstallStateStatus::UpdateAvailable, installation: $latest),
            $latest->status === InstallationStatus::Delivered => $this->state(InstallStateStatus::Delivered, installation: $latest),
            ! $sameBuild => $this->state(InstallStateStatus::Get),
            $latest->status === InstallationStatus::Preparing => $this->state(InstallStateStatus::Preparing, progress: $this->progress($latest), installation: $latest),
            in_array($latest->status, [InstallationStatus::ReadyToInstall, InstallationStatus::Authorized, InstallationStatus::ManifestFetched], true) => $this->state(InstallStateStatus::ReadyToInstall, installation: $latest),
            $latest->status === InstallationStatus::Failed => $this->state(InstallStateStatus::Failed, installation: $latest, rawReason: $latest->status_reason),
            default => $this->state(InstallStateStatus::Get),
        };
    }

    private function device(User $user): ?Device
    {
        if (! array_key_exists($user->id, $this->devices)) {
            $this->devices[$user->id] = $this->currentDevice->resolve(request())?->loadMissing('latestRegistration');
        }

        return $this->devices[$user->id];
    }

    private function progress(Installation $installation): ?float
    {
        return match ($installation->signedBuild?->status->value) {
            'SIGNING_PENDING' => 0.2,
            'SIGNING' => 0.5,
            'SIGNED' => 0.8,
            'SIGNATURE_VERIFIED' => 0.9,
            default => null,
        };
    }

    /**
     * @return array{status: string, reason: string|null, progress: float|null, installation_id: string|null}
     */
    private function state(
        InstallStateStatus $status,
        ?ErrorCode $reason = null,
        ?float $progress = null,
        ?Installation $installation = null,
        ?string $rawReason = null,
    ): array {
        return [
            'status' => $status->value,
            'reason' => $reason !== null ? $reason->value : $rawReason,
            'progress' => $progress,
            'installation_id' => $installation?->public_id,
        ];
    }
}
