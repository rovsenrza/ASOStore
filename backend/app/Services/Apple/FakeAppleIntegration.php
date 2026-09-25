<?php

namespace App\Services\Apple;

use App\Enums\AppleDeviceStatus;
use App\Models\AppleTeam;
use Illuminate\Contracts\Cache\Repository;
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
