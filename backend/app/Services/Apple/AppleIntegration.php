<?php

namespace App\Services\Apple;

use App\Models\AppleTeam;

/**
 * Everything the app needs from Apple for device registration. Drivers:
 * fake (local development), disabled (no account yet), appstoreconnect.
 * Selected by STOREFRONT_APPLE_DRIVER.
 */
interface AppleIntegration
{
    /**
     * False when this driver cannot talk to Apple for the team (no account,
     * no credentials). Registrations then wait instead of failing.
     */
    public function isConfigured(AppleTeam $team): bool;

    /**
     * @throws AppleException
     */
    public function findDevice(AppleTeam $team, string $udid): ?AppleDevice;

    /**
     * @throws AppleException
     */
    public function registerDevice(AppleTeam $team, string $udid, string $name): AppleDevice;

    /**
     * @throws AppleException
     */
    public function getDevice(AppleTeam $team, string $appleDeviceId): AppleDevice;

    /**
     * @throws AppleException
     */
    public function verifyCredentials(AppleTeam $team): void;
}
