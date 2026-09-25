<?php

use App\Enums\ArtifactStatus;
use App\Enums\DeviceRegistrationStatus;
use App\Enums\InstallationStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\SignedBuildStatus;
use App\StateMachines\StatusEnum;

dataset('machines', [
    'artifact' => [ArtifactStatus::class, ArtifactStatus::Uploaded],
    'signed build' => [SignedBuildStatus::class, SignedBuildStatus::SigningPending],
    'device registration' => [DeviceRegistrationStatus::class, DeviceRegistrationStatus::Enrolled],
    'installation' => [InstallationStatus::class, InstallationStatus::Preparing],
    'pipeline job' => [PipelineJobStatus::class, PipelineJobStatus::Queued],
]);

it('only allows moves to other states of the same machine', function (string $enum) {
    foreach ($enum::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            expect($to)->toBeInstanceOf($enum)->not->toBe($from);
        }
    }
})->with('machines');

it('can reach every state from the initial state', function (string $enum, StatusEnum $initial) {
    $seen = [$initial->value => true];
    $queue = [$initial];

    while ($queue !== []) {
        foreach (array_shift($queue)->allowedTransitions() as $next) {
            if (! isset($seen[$next->value])) {
                $seen[$next->value] = true;
                $queue[] = $next;
            }
        }
    }

    $unreachable = array_diff(array_map(fn ($case) => $case->value, $enum::cases()), array_keys($seen));

    expect($unreachable)->toBe([]);
})->with('machines');

it('rejects moves across machines', function () {
    expect(ArtifactStatus::Uploaded->canTransitionTo(PipelineJobStatus::Queued))->toBeFalse();
});

it('follows the artifact lifecycle from FULL_PLAN §5.2', function () {
    $happyPath = [
        ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting,
        ArtifactStatus::ProvenanceReview, ArtifactStatus::CompatibilityCheck, ArtifactStatus::Ready,
        ArtifactStatus::Published,
    ];

    foreach (array_slice($happyPath, 0, -1) as $index => $from) {
        expect($from->canTransitionTo($happyPath[$index + 1]))->toBeTrue();
    }

    // A human provenance review can never be skipped on the way to READY.
    expect(ArtifactStatus::Inspecting->canTransitionTo(ArtifactStatus::Ready))->toBeFalse()
        ->and(ArtifactStatus::Inspecting->canTransitionTo(ArtifactStatus::CompatibilityCheck))->toBeFalse()
        ->and(ArtifactStatus::Quarantined->canTransitionTo(ArtifactStatus::Ready))->toBeFalse()
        // Only READY artifacts can be published.
        ->and(ArtifactStatus::CompatibilityCheck->canTransitionTo(ArtifactStatus::Published))->toBeFalse();
});

it('treats rejection, revocation and expiry as final for artifacts', function (ArtifactStatus $status) {
    expect($status->isTerminal())->toBeTrue();
})->with([ArtifactStatus::Rejected, ArtifactStatus::ProvenanceFailed, ArtifactStatus::Revoked, ArtifactStatus::Expired]);

it('never lets a blocked device registration become eligible without going back through Apple', function (DeviceRegistrationStatus $blocked) {
    expect($blocked->canTransitionTo(DeviceRegistrationStatus::Eligible))->toBeFalse()
        ->and($blocked->allowedTransitions())->toBe([DeviceRegistrationStatus::ApplePending]);
})->with([DeviceRegistrationStatus::QuotaBlocked, DeviceRegistrationStatus::NoEligibleTeam, DeviceRegistrationStatus::AppleFailed]);

it('ends installations at DELIVERED, FAILED or EXPIRED', function () {
    $terminal = array_values(array_filter(InstallationStatus::cases(), fn ($case) => $case->isTerminal()));

    expect($terminal)->toBe([InstallationStatus::Delivered, InstallationStatus::Failed, InstallationStatus::Expired]);
});

it('lets an admin retry a permanently failed pipeline job', function () {
    expect(PipelineJobStatus::FailedPermanent->canTransitionTo(PipelineJobStatus::Queued))->toBeTrue()
        ->and(PipelineJobStatus::Succeeded->isTerminal())->toBeTrue()
        ->and(PipelineJobStatus::Cancelled->isTerminal())->toBeTrue();
});

it('requires a verified signature before a signed build is deliverable', function () {
    expect(SignedBuildStatus::Signed->canTransitionTo(SignedBuildStatus::Deliverable))->toBeFalse()
        ->and(SignedBuildStatus::SignatureVerified->canTransitionTo(SignedBuildStatus::Deliverable))->toBeTrue();
});
