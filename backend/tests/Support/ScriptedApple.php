<?php

namespace Tests\Support;

use App\Enums\AppleDeviceStatus;
use App\Models\AppleTeam;
use App\Services\Apple\AppleDevice;
use App\Services\Apple\AppleIntegration;
use App\Services\Apple\AppleProfile;
use Closure;
use DateTimeImmutable;

/**
 * Apple driver whose answers a test decides, and which records every call.
 */
final class ScriptedApple implements AppleIntegration
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, AppleDevice> */
    public array $known = [];

    public ?Closure $onRegister = null;

    public ?Closure $onGet = null;

    public bool $configured = true;

    public function isConfigured(AppleTeam $team): bool
    {
        return $this->configured;
    }

    public function findDevice(AppleTeam $team, string $udid): ?AppleDevice
    {
        $this->calls[] = "find:{$udid}";

        return $this->known[$udid] ?? null;
    }

    public function registerDevice(AppleTeam $team, string $udid, string $name): AppleDevice
    {
        $this->calls[] = "register:{$udid}";
        $device = $this->onRegister ? ($this->onRegister)($udid) : new AppleDevice('APPLE-'.substr($udid, -4), $udid, AppleDeviceStatus::Enabled);

        return $this->known[$udid] = $device;
    }

    public function getDevice(AppleTeam $team, string $appleDeviceId): AppleDevice
    {
        $this->calls[] = "get:{$appleDeviceId}";

        return ($this->onGet)($appleDeviceId);
    }

    public function verifyCredentials(AppleTeam $team): void
    {
        $this->calls[] = 'verify';
    }

    /** @var array<string, string> Bundle resource ID => identifier. */
    public array $bundles = [];

    public ?Closure $onCreateProfile = null;

    public function findCertificate(AppleTeam $team, string $serialNumber): ?string
    {
        $this->calls[] = "certificate:{$serialNumber}";

        return 'CERT-'.$serialNumber;
    }

    public function ensureBundleId(AppleTeam $team, string $identifier, string $name): string
    {
        $this->calls[] = "bundle:{$identifier}";
        $resource = 'BUNDLE-'.substr(md5($identifier), 0, 8);
        $this->bundles[$resource] = $identifier;

        return $resource;
    }

    public function createAdHocProfile(AppleTeam $team, string $name, string $bundleIdResource, string $certificateId, string $appleDeviceId): AppleProfile
    {
        $this->calls[] = "profile:{$bundleIdResource}:{$appleDeviceId}";
        if ($this->onCreateProfile !== null) {
            return ($this->onCreateProfile)($team, $bundleIdResource, $appleDeviceId);
        }

        $udids = array_values(array_map(fn (AppleDevice $device) => $device->udid, array_filter($this->known, fn (AppleDevice $device) => $device->id === $appleDeviceId)));
        $uuid = 'UUID-'.substr(md5($bundleIdResource.$appleDeviceId.count($this->calls)), 0, 12);

        return new AppleProfile(
            'PROFILE-'.substr($uuid, 5),
            $uuid,
            $name,
            base64_encode(self::mobileprovision($uuid, $team->apple_team_id, $this->bundles[$bundleIdResource] ?? 'unknown', $udids)),
            new DateTimeImmutable('+1 year'),
        );
    }

    /** @var array<string, int> */
    public array $deviceCounts = [];

    public function countDevicesByFamily(AppleTeam $team): array
    {
        $this->calls[] = 'count-devices';

        return $this->deviceCounts;
    }

    public function deleteProfile(AppleTeam $team, string $profileId): void
    {
        $this->calls[] = "delete-profile:{$profileId}";
    }

    /**
     * @param  list<string>  $udids
     */
    public static function mobileprovision(string $uuid, string $teamId, string $bundleId, array $udids): string
    {
        return "0\x82CMS".IpaBuilder::plist([
            'UUID' => $uuid,
            'Name' => 'Test profile',
            'TeamIdentifier' => [$teamId],
            'ProvisionedDevices' => $udids,
            'ExpirationDate' => new \DateTime('+1 year'),
            'Entitlements' => ['application-identifier' => $teamId.'.'.$bundleId, 'get-task-allow' => false],
        ])."\x00SIG";
    }

    public static function install(): self
    {
        $apple = new self;
        app()->instance(AppleIntegration::class, $apple);

        return $apple;
    }
}
