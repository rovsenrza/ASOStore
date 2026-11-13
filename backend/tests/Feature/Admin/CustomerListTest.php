<?php

use App\Enums\RoleSlug;
use App\Models\Device;

// Customer list and card: account, access and iPhones on one screen; full UDIDs for admins only.

beforeEach(function () {
    connectFakeAppleTeam();
    $customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->customer = $customer;
    $this->device = Device::sole();
});

it('shows each customer with their devices, full UDID for admins', function () {
    asStaff(userWithRoles(RoleSlug::Admin))->getJson('/api/v1/admin/subscriptions?state=all&q='.$this->customer->id)
        ->assertOk()
        ->assertJsonPath('data.0.device_list.0.udid', TEST_UDID)
        ->assertJsonPath('data.0.device_list.0.registration', 'ELIGIBLE')
        ->assertJsonPath('data.0.device_list.0.team', 'FAKE000001');
});

it('masks the UDID for support in the list, the card and the devices page', function () {
    $staff = asStaff(userWithRoles(RoleSlug::Support));
    $list = $staff->getJson('/api/v1/admin/subscriptions?state=all&q='.$this->customer->id)
        ->assertOk()->assertJsonPath('data.0.device_list.0.udid', null)->assertJsonPath('data.0.device_list.0.udid_hint', '••••-6F70');
    $card = $this->getJson("/api/v1/admin/users/{$this->customer->public_id}/profile")->assertOk()->assertJsonPath('data.devices.0.udid', null);
    $devices = $this->getJson('/api/v1/admin/devices')->assertOk();

    foreach ([$list, $card, $devices] as $response) {
        expect($response->getContent())->not->toContain(TEST_UDID);
    }
});

it('finds the owner by full UDID or its last four characters', function () {
    asStaff(userWithRoles(RoleSlug::Admin));
    $this->getJson('/api/v1/admin/subscriptions?'.http_build_query(['state' => 'all', 'q' => strtolower(TEST_UDID)]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $this->customer->id);
    $this->getJson('/api/v1/admin/subscriptions?state=all&q=6f70')
        ->assertOk()->assertJsonPath('data.0.number', $this->customer->id);
    $this->getJson('/api/v1/admin/devices?'.http_build_query(['q' => TEST_UDID]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.udid', TEST_UDID);
});

it('puts the account settings and the full UDID in the admin card', function () {
    asStaff(userWithRoles(RoleSlug::Admin))->getJson("/api/v1/admin/users/{$this->customer->public_id}/profile")
        ->assertOk()
        ->assertJsonPath('data.devices.0.udid', TEST_UDID)
        ->assertJsonPath('data.account.roles', ['customer'])
        ->assertJsonPath('data.account.totp_enabled', false);
});

it('lists accounts that never had access under none', function () {
    $staff = userWithRoles(RoleSlug::Support);
    asStaff($staff)->getJson('/api/v1/admin/subscriptions?state=none')
        ->assertOk()->assertJsonFragment(['number' => $staff->id])->assertJsonMissing(['number' => $this->customer->id]);
});

it('shows a Telegram chat linked to the account even without a bot purchase, and finds the account by it', function () {
    $chat = \App\Models\TelegramStoreCustomer::query()->create(['telegram_user_id' => 1101921201, 'chat_id' => 1101921201, 'username' => 'linked_chat', 'first_name' => 'R', 'referral_code' => 'linked01']);
    \App\Models\TelegramAccountLink::query()->create(['customer_id' => $chat->id, 'user_id' => $this->customer->id, 'via' => 'admin']);

    asStaff(userWithRoles(RoleSlug::Admin))->getJson("/api/v1/admin/users/{$this->customer->public_id}/profile")
        ->assertOk()->assertJsonPath('data.telegram.0.username', 'linked_chat');
    $this->getJson('/api/v1/admin/subscriptions?state=all&q=@linked_chat')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $this->customer->id);
});
