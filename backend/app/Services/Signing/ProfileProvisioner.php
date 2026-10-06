<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\SigningProfile;
use App\Models\TeamAppEligibility;
use App\Services\Apple\AppGroupProvisioner;
use App\Services\Apple\AppGroupUnavailable;
use App\Services\Apple\AppleCredentialsException;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Apple\AppleRetryableException;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Pipeline\RetryLater;
use Closure;
use Illuminate\Console\Application;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;

/**
 * Ensures an ad hoc profile exists for (team, bundle ID, device)
 * (IMPLEMENTATION_PLAN D10, P6-BE-01), or for (team, bundle ID) listing every
 * eligible device of the team (ensureShared, for builds shared by the team).
 * Profiles are recreated, never edited. The team is the one the device is
 * registered with; the certificate is one a runner has reported holding.
 */
class ProfileProvisioner
{
    public function __construct(
        private readonly AppleIntegration $apple,
        private readonly AuditService $audit,
        private readonly AppGroupProvisioner $appGroups,
    ) {}

    /**
     * Profiles for the app and each of its extensions (one per bundle ID); returns the app's.
     */
    public function ensure(AppArtifact $artifact, Device $device): SigningProfile
    {
        $registration = $device->latestRegistration;
        if ($registration?->status !== DeviceRegistrationStatus::Eligible || $registration->apple_device_id === null) {
            throw new SigningUnavailable('DEVICE_NOT_ELIGIBLE', 'The device is not registered with an Apple team.');
        }

        $team = $registration->team;
        $bundle = $artifact->signingBundleIdentifier();
        if (config('storefront.artifacts.require_team_eligibility') && ! TeamAppEligibility::allows($team->id, $bundle)) {
            throw new SigningUnavailable('TEAM_NOT_ELIGIBLE', "Team {$team->apple_team_id} is not approved for {$bundle}.");
        }
        $certificate = $this->certificate($team->id);

        // The page warm-up and the install may ask at once: one maker per (device, app).
        return $this->locked("signing-profiles:{$device->id}:{$bundle}",
            fn () => $this->makeProfiles($artifact, ProfileTarget::forDevice($registration, $device), $certificate, $bundle));
    }

    /**
     * Profiles listing every eligible device of the team, for a build the whole team shares.
     * A profile made for the same device set is reused; a changed set gets new profiles, and
     * the older ones stay valid for the builds that embed them.
     */
    public function ensureShared(AppArtifact $artifact, AppleTeam $team): SigningProfile
    {
        $bundle = $artifact->signingBundleIdentifier();
        if (config('storefront.artifacts.require_team_eligibility') && ! TeamAppEligibility::allows($team->id, $bundle)) {
            throw new SigningUnavailable('TEAM_NOT_ELIGIBLE', "Team {$team->apple_team_id} is not approved for {$bundle}.");
        }
        $certificate = $this->certificate($team->id);

        return $this->locked("signing-profiles:team:{$team->id}:{$bundle}",
            fn () => $this->makeProfiles($artifact, ProfileTarget::forTeam($team), $certificate, $bundle));
    }

    /**
     * @param  Closure(): SigningProfile  $make
     */
    private function locked(string $key, Closure $make): SigningProfile
    {
        try {
            return Cache::lock($key, 300)->block(120, $make);
        } catch (LockTimeoutException) {
            throw new RetryLater('Profiles for this app are still being made.', 15);
        }
    }

    private function makeProfiles(AppArtifact $artifact, ProfileTarget $target, Certificate $certificate, string $bundle): SigningProfile
    {
        $team = $target->team;
        $name = (string) $artifact->app?->name;

        $capabilities = Capabilities::fromEntitlements($artifact->inspection['entitlements'] ?? []);
        $extensions = array_map(fn (array $extension) => $extension + ['capabilities' => Capabilities::fromEntitlements($extension['entitlements'])], $artifact->signingExtensions());
        // One App Group per app, shared by the app and its extensions, named after the signing ID.
        $needsGroup = in_array('APP_GROUPS', array_merge($capabilities, ...array_column($extensions, 'capabilities')), true);
        $group = $needsGroup ? 'group.'.$bundle : null;

        // The app first: it creates the App Group its extensions join.
        $main = $this->ensureOne($target, $certificate, $bundle, $name, $capabilities, $group);

        $pending = array_values(array_filter($extensions, fn (array $extension) => ! $this->hasCurrentProfile(
            $target, $certificate, $extension['bundle_identifier'], self::extensionGroup($extension, $group))));
        // Each new profile is several Apple calls in a row; extensions are independent, so they run side by side.
        $ids = [$target->toArray(), $certificate->id];
        $results = count($pending) > 1
            ? self::runSideBySide(array_map(fn (array $extension) => self::extensionTask($ids, $extension, $name, $group), $pending))
            : array_map(fn (array $extension) => $this->ensureExtension(...$ids, extension: $extension, name: $name, group: $group), $pending);

        $failures = array_filter($results);
        $retry = array_filter($failures, fn (array $failure) => $failure['error'] === 'retry');
        if ($retry !== []) {
            throw new RetryLater(reset($retry)['message'], max(array_column($retry, 'seconds')));
        }
        if ($failures !== []) {
            $failure = reset($failures);
            throw new SigningUnavailable($failure['reason'], $failure['message']);
        }

        return $main;
    }

    /**
     * Concurrency::run() with a longer timeout: its child processes stop after 60 seconds,
     * and App Group assignments wait for each other at the portal.
     *
     * @param  list<Closure(): array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private static function runSideBySide(array $tasks): array
    {
        if (config('concurrency.default') === 'sync') {
            return array_map(fn (Closure $task) => $task(), $tasks);
        }

        $command = Application::formatCommandString('invoke-serialized-closure');
        $results = Process::pool(function (Pool $pool) use ($tasks, $command) {
            foreach ($tasks as $key => $task) {
                $pool->as((string) $key)->path(base_path())->timeout(240)
                    ->env(['LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task)))])
                    ->command($command);
            }
        })->start()->wait();

        return array_map(function (int $key) use ($results) {
            $output = $results[(string) $key]->throw()->output();
            $decoded = json_decode(substr($output, 0, strpos($output, "\x1f\x8b") ?: null), true);
            if (! ($decoded['successful'] ?? false)) {
                throw new RuntimeException(($decoded['exception'] ?? 'Error').': '.($decoded['message'] ?? mb_substr($output, 0, 500)));
            }

            return unserialize($decoded['result']);
        }, array_keys($tasks));
    }

    /**
     * Its own method: the closure is serialized from its source, which must hold no other closure on the line.
     *
     * @param  array{array<string, mixed>, int}  $ids  the target (ProfileTarget::toArray) and the certificate ID
     * @param  array{bundle_identifier: string, path: string, capabilities: list<string>}  $extension
     */
    private static function extensionTask(array $ids, array $extension, string $name, ?string $group): Closure
    {
        return static fn () => app(self::class)->ensureExtension(...$ids, extension: $extension, name: $name, group: $group);
    }

    /**
     * One extension's profile. Runs in a child process, which exceptions do not cross intact,
     * so failures come back as data.
     *
     * @param  array{bundle_identifier: string, path: string, capabilities: list<string>}  $extension
     * @return array{error?: 'retry'|'unavailable', reason?: string, message?: string, seconds?: int}
     */
    public function ensureExtension(array $target, int $certificateId, array $extension, string $name, ?string $group): array
    {
        try {
            $this->ensureOne(ProfileTarget::fromArray($target), Certificate::query()->findOrFail($certificateId), $extension['bundle_identifier'],
                $name.' '.basename($extension['path'], '.appex'), $extension['capabilities'], self::extensionGroup($extension, $group));

            return [];
        } catch (RetryLater $e) {
            return ['error' => 'retry', 'message' => $e->getMessage(), 'seconds' => $e->seconds];
        } catch (SigningUnavailable $e) {
            return ['error' => 'unavailable', 'reason' => $e->reason, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array{capabilities: list<string>}  $extension
     */
    private static function extensionGroup(array $extension, ?string $group): ?string
    {
        return in_array('APP_GROUPS', $extension['capabilities'], true) ? $group : null;
    }

    /**
     * True when ensureOne() would keep the existing profile without calling Apple.
     */
    private function hasCurrentProfile(ProfileTarget $target, Certificate $certificate, string $bundle, ?string $group): bool
    {
        $profile = $target->scope(SigningProfile::query())->where('bundle_identifier', $bundle)->first();

        return $profile !== null && $profile->isUsable() && $profile->certificate_id === $certificate->id
            && ($group === null || self::carriesGroup($profile, $group));
    }

    /**
     * The extensions' profiles for a build whose app profile is $main.
     *
     * @return list<array{path: string, bundle_identifier: string, profile: SigningProfile}>
     */
    public function extensionProfiles(AppArtifact $artifact, SigningProfile $main): array
    {
        $profiles = [];
        foreach ($artifact->signingExtensions() as $extension) {
            // Made with the app's profile: the same device, or the same device set.
            $profile = SigningProfile::query()
                ->where(['apple_team_id' => $main->apple_team_id, 'bundle_identifier' => $extension['bundle_identifier']])
                ->when($main->isShared(),
                    fn ($query) => $query->whereNull('device_id')->where('cohort', $main->cohort),
                    fn ($query) => $query->where('device_id', $main->device_id))
                ->first() ?? throw new SigningUnavailable('EXTENSION_PROFILE_MISSING', "No profile for {$extension['bundle_identifier']}.");
            $profiles[] = ['path' => $extension['path'], 'bundle_identifier' => $extension['bundle_identifier'], 'profile' => $profile];
        }

        return $profiles;
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function ensureOne(ProfileTarget $target, Certificate $certificate, string $bundle, string $name, array $capabilities, ?string $group = null): SigningProfile
    {
        $team = $target->team;
        $profile = $target->scope(SigningProfile::query())->where('bundle_identifier', $bundle)->first();
        if ($profile !== null && $profile->isUsable() && $profile->certificate_id === $certificate->id) {
            // A profile made before its App Group existed lacks it: replace it once the group is assigned,
            // otherwise keep using it (no new profile per install while the portal is unavailable).
            if ($group === null || self::carriesGroup($profile, $group) || ! $this->assignGroup($team, $group, $bundle, $name)) {
                return $profile;
            }
            $group = null; // assigned just now
        }

        try {
            $certificate->apple_certificate_id ??= $this->apple->findCertificate($team, $certificate->serial_number)
                ?? throw new SigningUnavailable('CERTIFICATE_NOT_IN_TEAM', 'Apple does not list the runner certificate for this team.');
            $certificate->save();

            if ($profile !== null) {
                try {
                    $this->apple->deleteProfile($team, $profile->apple_profile_id);
                } catch (AppleRetryableException $e) {
                    throw $e;
                } catch (Throwable) {
                    // Already gone at Apple; the new profile replaces it either way.
                }
            }

            $bundleResource = $this->apple->ensureBundleId($team, $bundle, $name);
            // Before the profile: Apple builds its entitlements from the App ID's capabilities and groups.
            $this->apple->ensureCapabilities($team, $bundleResource, $capabilities);
            if ($group !== null) {
                $this->assignGroup($team, $group, $bundle, $name);
            }
            // Apple refuses a second profile with the same name, and profiles this service
            // does not know about can exist in the team (another environment, a restored
            // database), so every name is unique. The bundle ID goes last: Apple keeps 100 characters.
            $created = $this->apple->createAdHocProfile(
                $team,
                sprintf('%s %s %s %s', config('storefront.brand'), $target->label, now()->format('ymdHis'), $bundle),
                $bundleResource,
                $certificate->apple_certificate_id,
                $target->appleDeviceIds,
            );
        } catch (AppleRetryableException $e) {
            throw new RetryLater($e->getMessage(), $e->retryAfterSeconds);
        } catch (AppleCredentialsException $e) {
            throw new SigningUnavailable('APPLE_NOT_CONNECTED', $e->getMessage());
        } catch (AppleException $e) {
            throw new SigningUnavailable($e->reason === 'APPLE_NOT_CONNECTED' ? 'APPLE_NOT_CONNECTED' : 'PROFILE_CREATION_FAILED', $e->getMessage());
        }

        $profile ??= new SigningProfile($target->attributes($bundle));
        $profile->fill([
            'certificate_id' => $certificate->id,
            'apple_profile_id' => $created->id,
            'uuid' => $created->uuid,
            'name' => $created->name,
            'status' => 'ACTIVE',
            'expires_at' => $created->expiresAt,
            'content_encrypted' => $created->content,
        ])->save();

        $this->audit->record('signing.profile_created', $profile, after: [
            'team' => $team->apple_team_id,
            'bundle_identifier' => $bundle,
        ] + ($target->isShared()
            ? ['cohort' => $target->cohort, 'devices' => count($target->deviceIds)]
            : ['device_id' => Device::query()->whereKey($target->deviceId)->value('public_id')]) + [
                'uuid' => $created->uuid,
                'expires_at' => $created->expiresAt?->format(DATE_ATOM),
            ], actor: Actor::system('signing'));

        return $profile;
    }

    /**
     * Assigns the app's App Group through the developer portal. Never blocks signing:
     * without a configured Apple ID, or with an expired session, the profile is made without
     * the group and the operator is told how to fix it.
     */
    private function assignGroup(AppleTeam $team, string $group, string $bundle, string $name): bool
    {
        try {
            return $this->appGroups->ensure($team, $group, $bundle, $name);
        } catch (AppGroupUnavailable $e) {
            Log::warning('signing.app_group_unavailable', ['group' => $group, 'bundle' => $bundle, 'reason' => $e->reason, 'message' => $e->getMessage()]);
            $this->audit->record('signing.app_group_unavailable', $team, after: ['group' => $group, 'bundle_identifier' => $bundle, 'reason' => $e->reason],
                reason: $e->reason === 'SESSION_EXPIRED' ? 'Apple ID session expired: run php artisan apple:portal-login' : mb_substr($e->getMessage(), 0, 500),
                actor: Actor::system('signing'));

            return false;
        }
    }

    private static function carriesGroup(SigningProfile $profile, string $group): bool
    {
        $parsed = ProvisioningProfile::parse((string) base64_decode((string) $profile->content_encrypted, true));

        return in_array($group, (array) ($parsed['entitlements']['com.apple.security.application-groups'] ?? []), true);
    }

    private function certificate(int $teamId): Certificate
    {
        return Certificate::query()
            ->where('apple_team_id', $teamId)
            ->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addDay()))
            ->whereNotNull('runner_id')
            ->orderByDesc('last_seen_at')
            ->first()
            ?? throw new SigningUnavailable('NO_SIGNING_CERTIFICATE', 'No runner holds a valid signing certificate for this team.');
    }
}
