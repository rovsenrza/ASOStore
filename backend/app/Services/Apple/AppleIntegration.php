<?php

namespace App\Services\Apple;

use App\Models\AppleTeam;

/**
 * Everything the app needs from Apple for device registration and ad hoc
 * profile provisioning (IMPLEMENTATION_PLAN P6-BE-01). Drivers:
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

    /**
     * Apple's resource ID for a certificate with this serial number, or null
     * when the team has no such certificate.
     *
     * @throws AppleException
     */
    public function findCertificate(AppleTeam $team, string $serialNumber): ?string;

    /**
     * Finds or registers an explicit App ID and returns Apple's resource ID.
     *
     * @throws AppleException
     */
    public function ensureBundleId(AppleTeam $team, string $identifier, string $name): string;

    /**
     * Creates an ad hoc profile for one certificate and one device.
     *
     * @throws AppleException
     */
    public function createAdHocProfile(AppleTeam $team, string $name, string $bundleIdResource, string $certificateId, string $appleDeviceId): AppleProfile;

    /**
     * @throws AppleException
     */
    public function deleteProfile(AppleTeam $team, string $profileId): void;
}
