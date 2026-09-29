<?php

namespace App\Services\Apple;

use App\Models\AppleTeam;

/**
 * Used until an Apple Developer account is connected. Nothing is sent to
 * Apple; enrolled devices wait with reason APPLE_NOT_CONNECTED.
 */
class DisabledAppleIntegration implements AppleIntegration
{
    public function isConfigured(AppleTeam $team): bool
    {
        return false;
    }

    public function findDevice(AppleTeam $team, string $udid): ?AppleDevice
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function registerDevice(AppleTeam $team, string $udid, string $name): AppleDevice
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function getDevice(AppleTeam $team, string $appleDeviceId): AppleDevice
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function verifyCredentials(AppleTeam $team): void
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function findCertificate(AppleTeam $team, string $serialNumber): ?string
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function ensureBundleId(AppleTeam $team, string $identifier, string $name): string
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function ensureCapabilities(AppleTeam $team, string $bundleIdResource, array $capabilityTypes): void
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function createAdHocProfile(AppleTeam $team, string $name, string $bundleIdResource, string $certificateId, string $appleDeviceId): AppleProfile
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function deleteProfile(AppleTeam $team, string $profileId): void
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }

    public function countDevicesByFamily(AppleTeam $team): array
    {
        throw new AppleException('Apple integration is disabled.', 'APPLE_NOT_CONNECTED');
    }
}
