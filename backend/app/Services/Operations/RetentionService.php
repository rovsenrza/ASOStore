<?php

namespace App\Services\Operations;

use App\Enums\ArtifactStatus;
use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Models\Device;
use App\Models\InstallationEvent;
use App\Models\SignedBuild;
use App\Models\UploadSession;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for artifacts, signed builds, upload leftovers, installation
 * events and UDIDs (FULL_PLAN §13, IMPLEMENTATION_PLAN P5-BE-04, P8-SEC-02).
 * Periods are defaults until the P0 retention decision (§10 Q8); rows that
 * the audit trail refers to are kept, only files and personal values go.
 */
class RetentionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        $days = config('storefront.retention');
        $disk = Storage::disk('artifacts');
        $summary = ['upload_sessions' => 0, 'rejected_artifacts' => 0, 'signed_builds' => 0, 'installation_events' => 0, 'udids' => 0];

        // Unfinished chunked uploads (P5-BE-04).
        foreach (UploadSession::query()->whereIn('status', ['OPEN', 'FAILED'])->where('updated_at', '<', now()->subHours($days['upload_session_hours']))->get() as $upload) {
            $disk->deleteDirectory("uploads/{$upload->public_id}");
            $upload->chunks()->delete();
            $upload->forceFill(['status' => 'EXPIRED'])->save();
            $summary['upload_sessions']++;
        }

        // Files of artifacts that never became installable. The row stays: it is audit evidence.
        $rejected = AppArtifact::query()
            ->whereIn('status', [ArtifactStatus::Rejected->value, ArtifactStatus::InspectionFailed->value, ArtifactStatus::ProvenanceFailed->value])
            ->whereNull('purged_at')
            ->where('updated_at', '<', now()->subDays($days['rejected_artifact_days']))
            ->get();
        foreach ($rejected as $artifact) {
            // The same file uploaded again after a rejection shares this path; keep it for that one.
            $shared = AppArtifact::query()->whereKeyNot($artifact->id)->where('storage_path', $artifact->storage_path)->whereNull('purged_at')->exists();
            if (! $shared) {
                $disk->delete($artifact->storage_path);
            }
            $artifact->forceFill(['purged_at' => now()])->save();
            $summary['rejected_artifacts']++;
        }

        // Signed builds that can no longer be installed.
        $builds = SignedBuild::query()
            ->whereIn('status', [SignedBuildStatus::Expired->value, SignedBuildStatus::Revoked->value, SignedBuildStatus::SigningFailed->value, SignedBuildStatus::ValidationFailed->value])
            ->whereNotNull('storage_path')
            ->whereNull('purged_at')
            ->where('updated_at', '<', now()->subDays($days['signed_build_days']))
            ->get();
        foreach ($builds as $build) {
            $disk->delete((string) $build->storage_path);
            $build->forceFill(['purged_at' => now()])->save();
            $summary['signed_builds']++;
        }

        $summary['installation_events'] = InstallationEvent::query()->where('created_at', '<', now()->subDays($days['installation_event_days']))->delete();

        // UDIDs of erased accounts once no membership year still counts the device.
        $devices = Device::query()
            ->whereNull('udid_purged_at')
            ->whereHas('user', fn ($user) => $user->whereNotNull('erased_at'))
            ->whereDoesntHave('registrations', fn ($registration) => $registration->whereHas('membershipYear', fn ($year) => $year->where('ends_at', '>', now())))
            ->get();
        foreach ($devices as $device) {
            // The cast encrypts the empty value; the HMAC blind index stays for uniqueness.
            $device->forceFill(['udid_encrypted' => '', 'udid_purged_at' => now()])->saveQuietly();
            $summary['udids']++;
        }

        if (array_sum($summary) > 0) {
            $this->audit->record('retention.applied', after: $summary, actor: Actor::system('retention'));
        }

        return $summary;
    }
}
