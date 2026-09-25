<?php

use App\Enums\RoleSlug;
use App\Jobs\RegisterDeviceJob;
use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    connectFakeAppleTeam();
    $customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->customer = $customer;
    $this->device = Device::sole();
});

it('lists devices with masked UDIDs and finds them by hint or email', function () {
    $response = asStaff(userWithRoles(RoleSlug::Support))->getJson('/api/v1/admin/devices?q=6F70')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.udid_hint', '••••-6F70')
        ->assertJsonPath('data.0.registration.status', 'ELIGIBLE');
    expect($response->getContent())->not->toContain(TEST_UDID);

    $this->getJson('/api/v1/admin/devices?'.http_build_query(['q' => $this->customer->email]))->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/devices?status=QUOTA_BLOCKED')->assertJsonCount(0, 'data');
});

it('shows registration history and the audit trail', function () {
    asStaff(userWithRoles(RoleSlug::Support))->getJson("/api/v1/admin/devices/{$this->device->public_id}")
        ->assertOk()
        ->assertJsonPath('data.registrations.0.status', 'ELIGIBLE')
        ->assertJsonPath('data.registrations.0.team', 'FAKE000001')
        ->assertJsonPath('data.history.0.action', 'device_registration.status_changed');
});

it('reveals the UDID only to admins, with a reason, and audits it', function () {
    asStaff(userWithRoles(RoleSlug::Support))->postJson("/api/v1/admin/devices/{$this->device->public_id}/reveal-udid", ['reason' => 'x'])
        ->assertForbidden();
    forgetGuards();

    $admin = userWithRoles(RoleSlug::Admin);
    asStaff($admin)->postJson("/api/v1/admin/devices/{$this->device->public_id}/reveal-udid")->assertUnprocessable();
    $this->postJson("/api/v1/admin/devices/{$this->device->public_id}/reveal-udid", ['reason' => 'Проверка в кабинете Apple'])
        ->assertOk()
        ->assertJsonPath('data.udid', TEST_UDID);

    $event = AuditLog::where('action', 'device.udid_revealed')->sole();
    expect($event->actor_id)->toBe($admin->id)
        ->and($event->reason)->toBe('Проверка в кабинете Apple')
        ->and(json_encode($event->after))->not->toContain(TEST_UDID);
});

it('queues a new Apple attempt on request', function () {
    Queue::fake();

    asStaff(userWithRoles(RoleSlug::Admin))->postJson("/api/v1/admin/devices/{$this->device->public_id}/sync")
        ->assertStatus(202)
        ->assertJsonPath('data.queued', true);

    Queue::assertPushed(RegisterDeviceJob::class);
});
