<?php

namespace Tests\Support;

use App\Enums\AppleDeviceStatus;
use App\Models\AppleTeam;
use App\Services\Apple\AppleDevice;
use App\Services\Apple\AppleIntegration;
use Closure;

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

    public static function install(): self
    {
        $apple = new self;
        app()->instance(AppleIntegration::class, $apple);

        return $apple;
    }
}
