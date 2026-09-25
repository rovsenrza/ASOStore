<?php

use App\Enums\ActorType;
use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Exceptions\IllegalStateTransition;
use App\Models\AppArtifact;
use App\Models\AuditLog;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Audit\Actor;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\Context;

it('moves a record and writes the audit event', function () {
    Context::add('request_id', 'req-12345678');
    $artifact = AppArtifact::factory()->create();
    $operator = User::factory()->create();

    app(StateMachine::class)->transition($artifact, ArtifactStatus::Hashing, 'upload complete', Actor::user($operator));

    expect($artifact->status)->toBe(ArtifactStatus::Hashing)
        ->and($artifact->fresh()->status)->toBe(ArtifactStatus::Hashing);

    $event = AuditLog::sole();
    expect($event->action)->toBe('app_artifact.status_changed')
        ->and($event->subject_type)->toBe('app_artifact')
        ->and($event->subject_id)->toBe($artifact->public_id)
        ->and($event->before)->toBe(['status' => 'UPLOADED'])
        ->and($event->after)->toBe(['status' => 'HASHING'])
        ->and($event->reason)->toBe('upload complete')
        ->and($event->actor_type)->toBe(ActorType::User)
        ->and($event->actor_id)->toBe($operator->id)
        ->and($event->request_id)->toBe('req-12345678');
});

it('refuses illegal moves without changing the record or auditing', function () {
    $artifact = AppArtifact::factory()->create();

    expect(fn () => app(StateMachine::class)->transition($artifact, ArtifactStatus::Published))
        ->toThrow(IllegalStateTransition::class);

    expect($artifact->fresh()->status)->toBe(ArtifactStatus::Uploaded)
        ->and(AuditLog::count())->toBe(0);
});

it('checks the stored state, not a stale in-memory copy', function () {
    $job = PipelineJob::factory()->create();
    $stale = PipelineJob::find($job->id);

    app(StateMachine::class)->transition($job, PipelineJobStatus::Running);
    app(StateMachine::class)->transition($job, PipelineJobStatus::Succeeded);

    // $stale still says QUEUED, but the row is SUCCEEDED, which is terminal.
    expect(fn () => app(StateMachine::class)->transition($stale, PipelineJobStatus::Running))
        ->toThrow(IllegalStateTransition::class);
});

it('writes extra columns in the same update and records them', function () {
    $artifact = AppArtifact::factory()->create();
    $machine = app(StateMachine::class);

    $machine->transition($artifact, ArtifactStatus::Rejected, extra: ['status_reason' => 'ENCRYPTED_BINARY']);

    expect($artifact->fresh()->status_reason)->toBe('ENCRYPTED_BINARY')
        ->and(AuditLog::sole()->after)->toBe(['status' => 'REJECTED', 'status_reason' => 'ENCRYPTED_BINARY']);
});

it('renders illegal transitions as ILLEGAL_STATE_TRANSITION', function () {
    $exception = new IllegalStateTransition('AppArtifact', ArtifactStatus::Uploaded, ArtifactStatus::Published);

    expect($exception->httpStatus())->toBe(409)
        ->and($exception->details)->toBe(['subject' => 'app_artifact', 'from' => 'UPLOADED', 'to' => 'PUBLISHED']);
});
