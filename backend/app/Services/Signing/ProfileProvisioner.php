<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Models\AppArtifact;
use App\Models\Certificate;
use App\Models\Device;
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

    public function ensure(AppArtifact $artifact, Device $device): SigningProfile
    {
        $registration = $device->latestRegistration;
        if ($registration?->status !== DeviceRegistrationStatus::Eligible || $registration->apple_device_id === null) {
            throw new SigningUnavailable('DEVICE_NOT_ELIGIBLE', 'The device is not registered with an Apple team.');
        }

        $team = $registration->team;
        $bundle = (string) $artifact->bundle_identifier;
        if (config('storefront.artifacts.require_team_eligibility') && ! TeamAppEligibility::allows($team->id, $bundle)) {
            throw new SigningUnavailable('TEAM_NOT_ELIGIBLE', "Team {$team->apple_team_id} is not approved for {$bundle}.");
        }
        $certificate = $this->certificate($team->id);

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

            $bundleResource = $this->apple->ensureBundleId($team, $bundle, $artifact->app->name);
            $created = $this->apple->createAdHocProfile(
                $team,
                sprintf('Storefront %s %s', $bundle, $device->udid_hint),
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
