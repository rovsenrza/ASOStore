<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Models\AppArtifact;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\SigningProfile;
use App\Models\TeamAppEligibility;
use App\Services\Apple\AppleCredentialsException;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Apple\AppleRetryableException;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Pipeline\RetryLater;
use Throwable;

/**
 * Ensures an ad hoc profile exists for (team, bundle ID, device)
 * (IMPLEMENTATION_PLAN D10, P6-BE-01). Profiles are recreated, never edited.
 * The team is the one the device is registered with; the certificate is one
 * a runner has reported holding.
 */
class ProfileProvisioner
{
    public function __construct(
        private readonly AppleIntegration $apple,
        private readonly AuditService $audit,
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
        $name = (string) $artifact->app?->name;

        $main = $this->ensureOne($registration, $device, $certificate, $bundle, $name, Capabilities::fromEntitlements($artifact->inspection['entitlements'] ?? []));
        foreach ($artifact->signingExtensions() as $extension) {
            $this->ensureOne($registration, $device, $certificate, $extension['bundle_identifier'],
                $name.' '.basename($extension['path'], '.appex'), Capabilities::fromEntitlements($extension['entitlements']));
        }

        return $main;
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
            $profile = SigningProfile::query()
                ->where(['apple_team_id' => $main->apple_team_id, 'bundle_identifier' => $extension['bundle_identifier'], 'device_id' => $main->device_id])
                ->first() ?? throw new SigningUnavailable('EXTENSION_PROFILE_MISSING', "No profile for {$extension['bundle_identifier']}.");
            $profiles[] = ['path' => $extension['path'], 'bundle_identifier' => $extension['bundle_identifier'], 'profile' => $profile];
        }

        return $profiles;
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function ensureOne(DeviceRegistration $registration, Device $device, Certificate $certificate, string $bundle, string $name, array $capabilities): SigningProfile
    {
        $team = $registration->team;
        $profile = SigningProfile::query()
            ->where(['apple_team_id' => $team->id, 'bundle_identifier' => $bundle, 'device_id' => $device->id])
            ->first();
        if ($profile !== null && $profile->isUsable() && $profile->certificate_id === $certificate->id) {
            return $profile;
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
            // Before the profile: Apple builds its entitlements from the App ID's capabilities.
            $this->apple->ensureCapabilities($team, $bundleResource, $capabilities);
            // Apple refuses a second profile with the same name, and profiles this service
            // does not know about can exist in the team (another environment, a restored
            // database), so every name is unique. The bundle ID goes last: Apple keeps 100 characters.
            $created = $this->apple->createAdHocProfile(
                $team,
                sprintf('%s %s %s %s', config('storefront.brand'), $device->udid_hint, now()->format('ymdHis'), $bundle),
                $bundleResource,
                $certificate->apple_certificate_id,
                $registration->apple_device_id,
            );
        } catch (AppleRetryableException $e) {
            throw new RetryLater($e->getMessage(), $e->retryAfterSeconds);
        } catch (AppleCredentialsException $e) {
            throw new SigningUnavailable('APPLE_NOT_CONNECTED', $e->getMessage());
        } catch (AppleException $e) {
            throw new SigningUnavailable($e->reason === 'APPLE_NOT_CONNECTED' ? 'APPLE_NOT_CONNECTED' : 'PROFILE_CREATION_FAILED', $e->getMessage());
        }

        $profile ??= new SigningProfile(['apple_team_id' => $team->id, 'bundle_identifier' => $bundle, 'device_id' => $device->id]);
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
            'device_id' => $device->public_id,
            'uuid' => $created->uuid,
            'expires_at' => $created->expiresAt?->format(DATE_ATOM),
        ], actor: Actor::system('signing'));

        return $profile;
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
