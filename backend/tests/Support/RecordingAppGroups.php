<?php

namespace Tests\Support;

use App\Models\AppleTeam;
use App\Services\Apple\AppGroupProvisioner;
use App\Services\Apple\AppGroupUnavailable;
use App\Services\Apple\SecretStore;

/**
 * AppGroupProvisioner without the developer portal: records what would be assigned.
 */
final class RecordingAppGroups extends AppGroupProvisioner
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    public ?AppGroupUnavailable $failure = null;

    public function __construct()
    {
        parent::__construct(app(SecretStore::class));
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function ensure(AppleTeam $team, string $groupId, string $bundleIdentifier, string $name): bool
    {
        $this->calls[] = [$groupId, $bundleIdentifier];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return true;
    }
}
