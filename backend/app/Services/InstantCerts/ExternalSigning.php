<?php

namespace App\Services\InstantCerts;

use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\ExternalCertificate;
use App\Models\SigningProfile;
use App\Services\Signing\SigningUnavailable;

/**
 * Profiles for an EXTERNAL (instant-certificate) team: no Apple calls. The device's one reseller
 * profile is recorded for the app's signing bundle ID and each extension's, so the rest of the
 * signing pipeline (lease, verify, deliver) works as for our own teams. The external runner signs
 * every bundle with it (lease `single_profile`).
 */
class ExternalSigning
{
    public function profileFor(AppArtifact $artifact, Device $device, AppleTeam $team): SigningProfile
    {
        $external = ExternalCertificate::query()
            ->where('apple_team_id', $team->id)
            ->where('udid_hash', $device->udid_hash)
            ->where('status', 'ACTIVE')
            ->latest('id')
            ->first();
        if ($external === null || ! $external->isUsable()) {
            throw new SigningUnavailable('NO_EXTERNAL_CERTIFICATE', 'The device has no usable instant certificate.');
        }
        $certificate = Certificate::query()->find($external->certificate_id);
        if ($certificate === null || $certificate->status !== 'ACTIVE') {
            throw new SigningUnavailable('NO_SIGNING_CERTIFICATE', 'The instant certificate is not available for signing.');
        }

        $bundles = array_merge(
            [$artifact->signingBundleIdentifier($team)],
            array_column($artifact->signingExtensions($team), 'bundle_identifier'),
        );
        $main = null;
        foreach ($bundles as $bundle) {
            $profile = SigningProfile::query()->firstOrNew([
                'apple_team_id' => $team->id, 'bundle_identifier' => $bundle, 'device_id' => $device->id,
            ]);
            if (! $profile->exists || $profile->uuid !== $external->profile_uuid || $profile->certificate_id !== $certificate->id) {
                $profile->fill([
                    'cohort' => null,
                    'device_ids' => null,
                    'certificate_id' => $certificate->id,
                    'apple_profile_id' => 'EXT-'.$external->id,
                    'uuid' => $external->profile_uuid,
                    'name' => mb_substr('Instant '.$bundle, 0, 190),
                    'status' => 'ACTIVE',
                    'expires_at' => $external->profile_expires_at,
                    'content_encrypted' => $external->profile_encrypted,
                ])->save();
            }
            $main ??= $profile;
        }

        return $main;
    }
}
