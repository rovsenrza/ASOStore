<?php

namespace App\Services\Apple;

use App\Enums\AppleDeviceStatus;
use App\Models\AppleTeam;
use CFPropertyList\CFPropertyList;
use CFPropertyList\CFTypeDetector;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use LogicException;

/**
 * Simulates App Store Connect for local development and tests. Devices stay
 * PROCESSING for STOREFRONT_APPLE_FAKE_PROCESSING_SECONDS, then turn ENABLED,
 * so the pending state can be seen in the UI. Refuses to run in production.
 */
class FakeAppleIntegration implements AppleIntegration
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $processingSeconds,
        bool $production,
    ) {
        if ($production) {
            throw new LogicException('The fake Apple integration must never run in production.');
        }
    }

    public function isConfigured(AppleTeam $team): bool
    {
        return true;
    }

    public function findDevice(AppleTeam $team, string $udid): ?AppleDevice
    {
        $record = $this->cache->get($this->key($team, $udid));

        return is_array($record) ? $this->present($record) : null;
    }

    public function registerDevice(AppleTeam $team, string $udid, string $name): AppleDevice
    {
        $record = [
            'id' => 'FAKE'.strtoupper(substr(hash('sha256', $team->apple_team_id.$udid), 0, 10)),
            'udid' => $udid,
            'registered_at' => now()->getTimestamp(),
        ];
        $this->cache->forever($this->key($team, $udid), $record);
        $this->cache->forever($this->key($team, 'id:'.$record['id']), $record);
        $all = (array) $this->cache->get($this->key($team, 'all'), []);
        $all[$record['id']] = true;
        $this->cache->forever($this->key($team, 'all'), $all);

        return $this->present($record);
    }

    public function getDevice(AppleTeam $team, string $appleDeviceId): AppleDevice
    {
        $record = $this->cache->get($this->key($team, 'id:'.$appleDeviceId));

        if (! is_array($record)) {
            throw new AppleException("Unknown fake device {$appleDeviceId}.", 'APPLE_DEVICE_NOT_FOUND');
        }

        return $this->present($record);
    }

    public function verifyCredentials(AppleTeam $team): void {}

    public function findCertificate(AppleTeam $team, string $serialNumber): ?string
    {
        return 'FAKECERT'.strtoupper(substr(hash('sha256', $team->apple_team_id.$serialNumber), 0, 8));
    }

    public function ensureBundleId(AppleTeam $team, string $identifier, string $name): string
    {
        $resource = 'FAKEBUNDLE'.strtoupper(substr(hash('sha256', $team->apple_team_id.$identifier), 0, 8));
        $this->cache->forever($this->key($team, 'bundle:'.$resource), $identifier);

        return $resource;
    }

    public function ensureCapabilities(AppleTeam $team, string $bundleIdResource, array $capabilityTypes): void
    {
        $key = $this->key($team, 'capabilities:'.$bundleIdResource);
        $this->cache->forever($key, array_values(array_unique([...(array) $this->cache->get($key, []), ...$capabilityTypes])));
    }

    /**
     * A structurally real .mobileprovision payload (CMS envelope simulated)
     * listing the device, so signature verification can be exercised locally.
     */
    public function createAdHocProfile(AppleTeam $team, string $name, string $bundleIdResource, string $certificateId, string|array $appleDeviceIds): AppleProfile
    {
        $udids = [];
        foreach ((array) $appleDeviceIds as $appleDeviceId) {
            $device = $this->cache->get($this->key($team, 'id:'.$appleDeviceId));
            if (! is_array($device)) {
                throw new AppleException("Unknown fake device {$appleDeviceId}.", 'APPLE_DEVICE_NOT_FOUND');
            }
            $udids[] = $device['udid'];
        }

        $bundle = $this->cache->get($this->key($team, 'bundle:'.$bundleIdResource), $bundleIdResource);
        $uuid = strtoupper((string) Str::uuid());
        $expires = new DateTimeImmutable('+1 year');

        $plist = new CFPropertyList;
        $plist->add((new CFTypeDetector(['castNumericStrings' => false]))->toCFType([
            'AppIDName' => $name,
            'Name' => $name,
            'UUID' => $uuid,
            'TeamIdentifier' => [$team->apple_team_id],
            'ProvisionedDevices' => $udids,
            'CreationDate' => new \DateTime,
            'ExpirationDate' => \DateTime::createFromImmutable($expires),
            'Entitlements' => [
                'application-identifier' => $team->apple_team_id.'.'.$bundle,
                'com.apple.developer.team-identifier' => $team->apple_team_id,
                'get-task-allow' => false,
                'keychain-access-groups' => [$team->apple_team_id.'.*'],
            ],
        ]));
        $content = base64_encode("0\x82FAKE-CMS".$plist->toXML()."\x00FAKE-SIGNATURE");

        return new AppleProfile('FAKEPROFILE'.substr($uuid, 0, 8), $uuid, $name, $content, $expires);
    }

    public function deleteProfile(AppleTeam $team, string $profileId): void {}

    /**
     * The fake does not know device classes; every device counts as an iPhone.
     */
    public function countDevicesByFamily(AppleTeam $team): array
    {
        return ['IPHONE' => count((array) $this->cache->get($this->key($team, 'all'), []))];
    }

    /**
     * @param  array{id: string, udid: string, registered_at: int}  $record
     */
    private function present(array $record): AppleDevice
    {
        $ready = now()->getTimestamp() - $record['registered_at'] >= $this->processingSeconds;

        return new AppleDevice($record['id'], $record['udid'], $ready ? AppleDeviceStatus::Enabled : AppleDeviceStatus::Processing);
    }

    private function key(AppleTeam $team, string $suffix): string
    {
        return "fake-apple:{$team->apple_team_id}:{$suffix}";
    }
}
