<?php

namespace App\Services\Catalog;

use App\Enums\ErrorCode;
use App\Enums\InstallStateStatus;
use App\Models\CatalogApp;
use App\Models\User;

/**
 * Decides the install CTA for one app and the caller. Clients must render the
 * CTA from this result only (FULL_PLAN §4.2, §11).
 *
 * Phase 1: nothing is installable yet. Device eligibility (Phase 3) and
 * preparation/installation states (Phase 6) extend this resolver.
 */
class InstallStateResolver
{
    /**
     * Relations that must be eager-loaded on the app before calling resolve().
     */
    public const REQUIRED_RELATIONS = ['publishedArtifact'];

    /**
     * @return array{status: string, reason: string|null, progress: float|null, installation_id: string|null}
     */
    public function resolve(CatalogApp $app, ?User $user): array
    {
        if ($app->publishedArtifact === null) {
            return $this->state(InstallStateStatus::Unavailable, ErrorCode::ArtifactNotInstallable);
        }

        if ($user === null) {
            return $this->state(InstallStateStatus::NotEligible, ErrorCode::Unauthenticated);
        }

        // Until device registration exists (Phase 3), no device can be eligible.
        return $this->state(InstallStateStatus::NotEligible, ErrorCode::DeviceNotEligible);
    }

    /**
     * @return array{status: string, reason: string|null, progress: float|null, installation_id: string|null}
     */
    private function state(InstallStateStatus $status, ?ErrorCode $reason = null): array
    {
        return [
            'status' => $status->value,
            'reason' => $reason?->value,
            'progress' => null,
            'installation_id' => null,
        ];
    }
}
