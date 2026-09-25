<?php

use App\Enums\DeviceRegistrationStatus;
use App\Enums\RoleSlug;
use App\Models\AppArtifact;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\MembershipYear;
use App\Models\RefreshToken;
use App\Models\SupportTicket;
use App\Models\UploadSession;
use App\Services\Operations\RetentionService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    connectFakeAppleTeam();
    $this->customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->device = Device::sole();
});

it('accepts support tickets from signed-out visitors and customers', function () {
    $this->postJson('/api/v1/support/tickets', ['topic' => 'INSTALL', 'message' => 'Storefront does not open'])->assertUnprocessable();
    $this->postJson('/api/v1/support/tickets', [
        'email' => 'visitor@example.com', 'topic' => 'INSTALL', 'message' => 'Storefront does not open after update', 'reference_request_id' => '01j8zq4m6r2x9d3k5v7w1y0b2c',
    ])->assertCreated()->assertJsonPath('data.status', 'OPEN');

    Sanctum::actingAs($this->customer);
    $this->postJson('/api/v1/support/tickets', ['topic' => 'DEVICE', 'message' => 'My iPhone is still pending'])->assertCreated();

    expect(SupportTicket::pluck('email')->all())->toBe(['visitor@example.com', $this->customer->email]);

    forgetGuards();
    asStaff(userWithRoles(RoleSlug::Support))->getJson('/api/v1/admin/support-tickets')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.reference_request_id', '01j8zq4m6r2x9d3k5v7w1y0b2c');
});

it('exports the account without the UDID and records a deletion request', function () {
    Sanctum::actingAs($this->customer);

    $export = $this->getJson('/api/v1/account/export')->assertOk();
    expect($export->json('data.account.email'))->toBe($this->customer->email)
        ->and($export->json('data.devices.0.udid_hint'))->toBe('••••-6F70')
        ->and($export->getContent())->not->toContain(TEST_UDID);

    $this->postJson('/api/v1/account/deletion-request')->assertStatus(202);
    $this->postJson('/api/v1/account/deletion-request')->assertStatus(202);

    expect($this->customer->refresh()->deletion_requested_at)->not->toBeNull()
        ->and(SupportTicket::where('topic', 'DATA_DELETION')->count())->toBe(1);
});

it('erases an account: sessions end, devices are disabled, personal data is replaced', function () {
    $this->customer->createToken('ios');
    RefreshToken::create([
        'user_id' => $this->customer->id, 'family_id' => 'f', 'token_hash' => str_repeat('c', 64),
        'access_token_id' => $this->customer->tokens()->value('id'), 'expires_at' => now()->addDay(),
    ]);
    $email = $this->customer->email;
    $admin = userWithRoles(RoleSlug::Admin);

    asStaff($admin)->postJson("/api/v1/admin/users/{$this->customer->public_id}/erase", ['confirm_email' => 'wrong@example.com', 'reason' => 'x'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/users/{$this->customer->public_id}/erase", ['confirm_email' => $email, 'reason' => 'Customer request, ticket 42'])->assertOk();

    $user = $this->customer->refresh();
    expect($user->erased_at)->not->toBeNull()
        ->and($user->email)->not->toBe($email)
        ->and($user->tokens()->count())->toBe(0)
        ->and(RefreshToken::sole()->revoked_at)->not->toBeNull()
        ->and($this->device->latestRegistration->status)->toBe(DeviceRegistrationStatus::Disabled)
        ->and(AuditLog::where('action', 'account.erased')->sole()->reason)->toBe('Customer request, ticket 42')
        ->and(json_encode(AuditLog::all()))->not->toContain($email);

    // The UDID stays while the membership year still counts the device, then it goes.
    app(RetentionService::class)->run();
    expect($this->device->refresh()->udid_purged_at)->toBeNull();

    MembershipYear::query()->update(['ends_at' => now()->subDay()]);
    app(RetentionService::class)->run();
    expect($this->device->refresh()->udid_purged_at)->not->toBeNull()
        ->and($this->device->udid_encrypted)->toBe('');
});

it('purges files of rejected artifacts and abandoned uploads after their retention period', function () {
    Storage::fake('artifacts');
    $manager = userWithRoles(RoleSlug::CatalogManager);
    $app = CatalogApp::factory()->create();

    $rejected = inspected(uploadIpa($manager, $app, 'not a zip'));
    $abandoned = asStaff($manager)->postJson('/api/v1/admin/uploads', [
        'app_id' => $app->public_id, 'filename' => 'Big.ipa', 'size_bytes' => 20, 'source_type' => 'OWN_BUILD',
        'declaration_version' => 'v1', 'declaration_accepted' => true,
    ])->json('data');
    Storage::disk('artifacts')->put("uploads/{$abandoned['id']}/chunks/0.part", 'partial');

    $this->travel(91)->days();
    $summary = app(RetentionService::class)->run();

    expect($summary['rejected_artifacts'])->toBe(1)
        ->and($summary['upload_sessions'])->toBe(1)
        ->and(AppArtifact::find($rejected->id)->purged_at)->not->toBeNull()
        ->and(UploadSession::where('public_id', $abandoned['id'])->sole()->status)->toBe('EXPIRED');
    Storage::disk('artifacts')->assertMissing($rejected->storage_path);
    Storage::disk('artifacts')->assertMissing("uploads/{$abandoned['id']}/chunks/0.part");
});
