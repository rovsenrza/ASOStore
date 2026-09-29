<?php

namespace App\Services\Installations;

use App\Enums\ArtifactStatus;
use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus;
use App\Enums\ErrorCode;
use App\Enums\InstallationStatus;
use App\Enums\SignedBuildStatus;
use App\Exceptions\ApiException;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\Installation;
use App\Models\InstallAuthorization;
use App\Models\SignedBuild;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Signing\SigningService;
use App\StateMachines\StateMachine;
use CFPropertyList\CFPropertyList;
use CFPropertyList\CFTypeDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Prepare → authorize → manifest → download (IMPLEMENTATION_PLAN §5.6).
 * Every step re-checks that the device is eligible, the artifact is still
 * published and the signed build is deliverable, so an unauthorized device,
 * an expired token or an unpublished artifact never reaches a manifest or IPA.
 */
class InstallationService
{
    public const TOKEN_MINUTES = 10;

    public const DOWNLOAD_MINUTES = 10;

    public function __construct(
        private readonly StateMachine $states,
        private readonly SigningService $signing,
        private readonly AuditService $audit,
    ) {}

    public function prepare(User $user, Device $device, CatalogApp $app): Installation
    {
        if ($device->user_id !== $user->id) {
            throw new ApiException(ErrorCode::DeviceNotEligible);
        }
        $this->assertDeviceEligible($device);

        $artifact = $app->publishedArtifact()->first();
        if ($artifact === null || $artifact->status !== ArtifactStatus::Published) {
            throw new ApiException(ErrorCode::ArtifactNotInstallable);
        }
        $this->assertCompatible($artifact, $device);

        return DB::transaction(function () use ($user, $device, $app, $artifact) {
            $active = Installation::query()
                ->where(['device_id' => $device->id, 'app_id' => $app->id])
                ->whereIn('status', array_map(fn (InstallationStatus $status) => $status->value, Installation::ACTIVE))
                ->lockForUpdate()
                ->get();

            foreach ($active as $installation) {
                if ($installation->artifact_id === $artifact->id) {
                    return $installation;
                }
                // A newer build was published meanwhile: the old attempt is replaced.
                $this->states->transition($installation, InstallationStatus::Failed, 'Superseded by a newer build.', Actor::user($user), extra: ['status_reason' => 'SUPERSEDED']);
            }

            $build = $this->signing->requestBuild($artifact, $device);
            $installation = Installation::create([
                'user_id' => $user->id,
                'device_id' => $device->id,
                'app_id' => $app->id,
                'artifact_id' => $artifact->id,
                'signed_build_id' => $build->id,
            ]);
            $this->event($installation, 'PREPARE_REQUESTED', ['signed_build_id' => $build->public_id]);
            $this->audit->record('installation.prepare_requested', $installation, after: [
                'app_id' => $app->public_id,
                'artifact_id' => $artifact->public_id,
                'device_id' => $device->public_id,
                'reused_build' => $build->isDeliverable(),
            ], actor: Actor::user($user));

            if ($build->isDeliverable()) {
                $this->markReady($installation);
            }

            return $installation;
        });
    }

    /**
     * @return array{installation_id: string, install_url: string, manifest_url: string, expires_at: string}
     */
    public function authorize(Installation $installation, Device $device, ?string $ip): array
    {
        return DB::transaction(function () use ($installation, $device, $ip) {
            $installation = Installation::query()->whereKey($installation->id)->lockForUpdate()->firstOrFail();
            if ($installation->device_id !== $device->id) {
                throw new ApiException(ErrorCode::NotFound);
            }
            if (! in_array($installation->status, [InstallationStatus::ReadyToInstall, InstallationStatus::Authorized, InstallationStatus::ManifestFetched], true)) {
                throw new ApiException(ErrorCode::ArtifactNotInstallable, details: ['status' => $installation->status->value]);
            }
            $this->assertInstallable($installation);

            if ($installation->status !== InstallationStatus::ReadyToInstall) {
                // A new link replaces the previous one.
                $this->states->transition($installation, InstallationStatus::ReadyToInstall, 'New install link requested.', Actor::user($installation->user));
            }

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $authorization = InstallAuthorization::create([
                'installation_id' => $installation->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::TOKEN_MINUTES),
                'ip' => $ip,
            ]);
            $this->states->transition($installation, InstallationStatus::Authorized, actor: Actor::user($installation->user));
            $this->event($installation, 'AUTHORIZED');

            $manifestUrl = route('api.install.manifest', ['token' => $token]);

            return [
                'installation_id' => $installation->public_id,
                'install_url' => 'itms-services://?action=download-manifest&url='.rawurlencode($manifestUrl),
                'manifest_url' => $manifestUrl,
                'expires_at' => $authorization->expires_at->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * The OTA manifest. The token is single-use and expires after TOKEN_MINUTES.
     */
    public function manifest(#[\SensitiveParameter] string $token, Request $request): string
    {
        return DB::transaction(function () use ($token, $request) {
            $authorization = InstallAuthorization::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($authorization === null || $authorization->manifest_fetched_at !== null || $authorization->expires_at->isPast()) {
                throw new ApiException(ErrorCode::InstallTokenExpired);
            }

            $installation = $authorization->installation;
            if ($installation->status !== InstallationStatus::Authorized) {
                throw new ApiException(ErrorCode::InstallTokenExpired);
            }
            $this->assertInstallable($installation);

            $authorization->forceFill(['manifest_fetched_at' => now()])->save();
            $this->states->transition($installation, InstallationStatus::ManifestFetched, actor: Actor::user($installation->user));
            $this->event($installation, 'MANIFEST_FETCHED', request: $request);

            $artifact = $installation->artifact;
            $app = $installation->app;
            $assets = [[
                'kind' => 'software-package',
                'url' => URL::temporarySignedRoute('api.downloads.installation', now()->addMinutes(self::DOWNLOAD_MINUTES), ['installation' => $installation->public_id]),
            ]];
            if ($app->iconUrl() !== null) {
                $assets[] = ['kind' => 'display-image', 'url' => url($app->iconUrl())];
                $assets[] = ['kind' => 'full-size-image', 'url' => url($app->iconUrl())];
            }

            $plist = new CFPropertyList;
            $plist->add((new CFTypeDetector(['castNumericStrings' => false]))->toCFType([
                'items' => [[
                    'assets' => $assets,
                    'metadata' => [
                        'bundle-identifier' => $artifact->signingBundleIdentifier(),
                        'bundle-version' => (string) $artifact->version,
                        'kind' => 'software',
                        'title' => $app->name,
                    ],
                ]],
            ]));

            return $plist->toXML(true);
        });
    }

    /**
     * Checks before streaming the signed IPA. Returns the file path on the artifacts disk.
     */
    public function downloadPath(Installation $installation): string
    {
        $this->assertInstallable($installation);
        if (! in_array($installation->status, [InstallationStatus::ManifestFetched, InstallationStatus::Delivered], true)) {
            throw new ApiException(ErrorCode::InstallTokenExpired);
        }

        return (string) $installation->signedBuild?->storage_path;
    }

    public function downloadStarted(Installation $installation, Request $request): void
    {
        $this->event($installation, 'DOWNLOAD_STARTED', ['range' => $request->header('Range')], $request);
    }

    /**
     * The last state the server can observe (IMPLEMENTATION_PLAN G13).
     */
    public function downloadCompleted(Installation $installation, Request $request): void
    {
        DB::transaction(function () use ($installation, $request) {
            $installation = Installation::query()->whereKey($installation->id)->lockForUpdate()->firstOrFail();
            // iOS may fetch the tail of the file more than once; record the first completion only.
            if ($installation->status === InstallationStatus::ManifestFetched) {
                $this->event($installation, 'DOWNLOAD_COMPLETED', request: $request);
                $this->states->transition($installation, InstallationStatus::Delivered, actor: Actor::user($installation->user), extra: ['delivered_at' => now()]);
            }
        });
    }

    public function buildReady(SignedBuild $build): void
    {
        $waiting = Installation::query()
            ->where('signed_build_id', $build->id)
            ->where('status', InstallationStatus::Preparing->value)
            ->get();

        foreach ($waiting as $installation) {
            $this->markReady($installation);
        }
    }

    public function buildFailed(SignedBuild $build, string $reason): void
    {
        $waiting = Installation::query()
            ->where('signed_build_id', $build->id)
            ->whereIn('status', array_map(fn (InstallationStatus $status) => $status->value, Installation::ACTIVE))
            ->get();

        foreach ($waiting as $installation) {
            $this->states->transition($installation, InstallationStatus::Failed, $reason, Actor::system('signing'), extra: ['status_reason' => $reason]);
            $this->event($installation, 'FAILED', ['reason' => $reason]);
        }
    }

    /**
     * An artifact was revoked or superseded: its signed builds stop being
     * deliverable and unfinished installations end.
     */
    public function artifactWithdrawn(AppArtifact $artifact, string $reason): void
    {
        $actor = Actor::system('catalog');
        $builds = SignedBuild::query()->where('artifact_id', $artifact->id)->where('status', SignedBuildStatus::Deliverable->value)->get();
        foreach ($builds as $build) {
            $to = $reason === 'REVOKED' ? SignedBuildStatus::Revoked : SignedBuildStatus::Expired;
            $this->states->transition($build, $to, $reason, $actor, extra: ['status_reason' => $reason]);
        }

        $installations = Installation::query()
            ->where('artifact_id', $artifact->id)
            ->whereIn('status', array_map(fn (InstallationStatus $status) => $status->value, Installation::ACTIVE))
            ->get();
        foreach ($installations as $installation) {
            $this->states->transition($installation, InstallationStatus::Failed, $reason, $actor, extra: ['status_reason' => 'ARTIFACT_'.$reason]);
            $this->event($installation, 'FAILED', ['reason' => 'ARTIFACT_'.$reason]);
        }
    }

    /**
     * Unused install links fall back to READY_TO_INSTALL so the user can ask again
     * (ExpireInstallTokenJob). Returns how many installations were reset.
     */
    public function expireStaleAuthorizations(): int
    {
        $stale = Installation::query()
            ->whereIn('status', [InstallationStatus::Authorized->value, InstallationStatus::ManifestFetched->value])
            ->whereDoesntHave('authorizations', fn ($query) => $query->where('expires_at', '>', now()->subMinutes(self::DOWNLOAD_MINUTES)))
            ->get();

        foreach ($stale as $installation) {
            $this->states->transition($installation, InstallationStatus::ReadyToInstall, 'Install link expired.', Actor::system('installations'));
            $this->event($installation, 'LINK_EXPIRED');
        }

        return $stale->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Installation $installation): array
    {
        $build = $installation->signedBuild;

        return [
            'id' => $installation->public_id,
            'status' => $installation->status->value,
            'status_reason' => $installation->status_reason,
            'app' => ['id' => $installation->app->public_id, 'name' => $installation->app->name, 'icon_url' => $installation->app->iconUrl()],
            'version' => $installation->artifact->version,
            'build_number' => $installation->artifact->build_number,
            'preparation' => [
                'stage' => $build?->status->value,
                'progress' => $build?->progress(),
            ],
            'delivered_at' => $installation->delivered_at?->toIso8601ZuluString(),
            'created_at' => $installation->created_at?->toIso8601ZuluString(),
            'updated_at' => $installation->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function markReady(Installation $installation): void
    {
        $this->states->transition($installation, InstallationStatus::ReadyToInstall, actor: Actor::system('signing'));
        $this->event($installation, 'READY');
    }

    private function assertDeviceEligible(Device $device): void
    {
        $status = $device->latestRegistration?->status;
        if ($status === DeviceRegistrationStatus::Eligible) {
            return;
        }

        throw new ApiException(match ($status) {
            null, DeviceRegistrationStatus::Enrolled, DeviceRegistrationStatus::ApplePending => ErrorCode::DevicePendingApple,
            DeviceRegistrationStatus::QuotaBlocked => ErrorCode::QuotaExhausted,
            DeviceRegistrationStatus::NoEligibleTeam => ErrorCode::NoEligibleTeam,
            default => ErrorCode::DeviceNotEligible,
        });
    }

    private function assertCompatible(AppArtifact $artifact, Device $device): void
    {
        $families = $artifact->inspection['bundle']['device_families'] ?? [1];
        // iPads run iPhone apps; iPhones need iPhone support.
        $supported = match ($device->device_family) {
            DeviceFamily::Iphone, DeviceFamily::Ipod => in_array(1, $families, true),
            DeviceFamily::Ipad => in_array(1, $families, true) || in_array(2, $families, true),
            DeviceFamily::Unknown => false,
        };
        $osTooOld = $device->os_version !== null && $artifact->min_ios_version !== null
            && version_compare($device->os_version, $artifact->min_ios_version, '<');

        if (! $supported || $osTooOld) {
            throw new ApiException(ErrorCode::IncompatibleDevice, details: [
                'min_ios_version' => $artifact->min_ios_version,
                'device_os_version' => $device->os_version,
            ]);
        }
    }

    private function assertInstallable(Installation $installation): void
    {
        $this->assertDeviceEligible($installation->device);

        if ($installation->artifact->status !== ArtifactStatus::Published) {
            throw new ApiException(ErrorCode::ArtifactNotInstallable);
        }
        $build = $installation->signedBuild;
        if ($build === null || ! $build->isDeliverable() || $build->device_id !== $installation->device_id) {
            throw new ApiException(ErrorCode::ArtifactNotInstallable);
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function event(Installation $installation, string $type, ?array $meta = null, ?Request $request = null): void
    {
        $request ??= app()->runningInConsole() ? null : request();

        $installation->events()->create([
            'type' => $type,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'request_id' => Context::get('request_id'),
            'meta' => $meta,
        ]);
    }
}
