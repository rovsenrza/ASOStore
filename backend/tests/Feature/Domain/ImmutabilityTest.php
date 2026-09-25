<?php

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\AuditLog;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Database-level guarantees from IMPLEMENTATION_PLAN §5.3. These go through
| the query builder on purpose, bypassing model guards.
*/

it('blocks updates and deletes of audit rows in the database', function () {
    app(AuditService::class)->record('test.event');

    expect(fn () => DB::table('audit_logs')->update(['action' => 'tampered']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('audit_logs')->value('action'))->toBe('test.event');
});

it('blocks audit edits at the model level too', function () {
    $event = app(AuditService::class)->record('test.event');

    expect(fn () => $event->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('blocks changes to artifact file identity', function (string $column, mixed $value) {
    $artifact = AppArtifact::factory()->create();

    expect(fn () => DB::table('app_artifacts')->where('id', $artifact->id)->update([$column => $value]))
        ->toThrow(QueryException::class, 'immutable');
})->with([
    ['sha256', str_repeat('0', 64)],
    ['size_bytes', 1],
    ['storage_path', 'elsewhere.ipa'],
    ['uploaded_by', null],
    ['declaration_version', 'forged'],
]);

it('blocks artifact deletion', function () {
    $artifact = AppArtifact::factory()->create();

    expect(fn () => DB::table('app_artifacts')->where('id', $artifact->id)->delete())->toThrow(QueryException::class, 'immutable');
});

it('still allows lifecycle and inspection updates on artifacts', function () {
    $artifact = AppArtifact::factory()->create();

    $artifact->update(['status' => ArtifactStatus::Hashing, 'bundle_identifier' => 'com.example.demo', 'inspection' => ['arch' => ['arm64']]]);

    expect($artifact->fresh())
        ->status->toBe(ArtifactStatus::Hashing)
        ->bundle_identifier->toBe('com.example.demo');
});

it('keeps audit rows free of secrets', function () {
    app(AuditService::class)->record('device.enrolled', after: ['udid' => '00008030-001A2B3C4D5E6F70', 'product' => 'iPhone15,2']);

    expect(AuditLog::sole()->after)->toBe(['udid' => '[REDACTED]', 'product' => 'iPhone15,2']);
});
