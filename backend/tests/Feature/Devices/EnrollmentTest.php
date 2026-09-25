<?php

use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus;
use App\Enums\RoleSlug;
use App\Jobs\SyncDeviceRegistrationsJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\EnrollmentChallenge;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->customer = subscribedCustomer();
});

describe('profile download', function () {
    it('serves a Profile Service .mobileconfig asking only for UDID, model and version', function () {
        $response = asBrowser()->actingAs($this->customer, 'web')->get('/api/v1/devices/enrollment-profile')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-apple-aspen-config')
            ->assertHeader('Content-Disposition', 'attachment; filename="storefront-enrollment.mobileconfig"');

        $plist = new CFPropertyList\CFPropertyList;
        $plist->parse($response->getContent());
        $profile = $plist->toArray();

        expect($profile['PayloadType'])->toBe('Profile Service')
            ->and($profile['PayloadContent']['DeviceAttributes'])->toBe(['UDID', 'PRODUCT', 'VERSION'])
            ->and($profile['PayloadContent']['URL'])->toContain('/api/v1/devices/enrollment/callback?challenge=');

        // Only a hash of the challenge is stored.
        expect(EnrollmentChallenge::sole()->challenge_hash)->toBe(hash('sha256', $profile['PayloadContent']['Challenge']));
    });

    it('requires an activated account', function () {
        $newcomer = userWithRoles(RoleSlug::Customer);

        asBrowser()->actingAs($newcomer, 'web')->getJson('/api/v1/devices/enrollment-profile')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    });
});

describe('device answer', function () {
    it('enrols the device, registers it with Apple and redirects back to the portal', function () {
        connectFakeAppleTeam();
        $challenge = enrollmentChallenge($this->customer);

        $response = postEnrollment($challenge, ['UDID' => strtolower(TEST_UDID), 'PRODUCT' => 'iPhone15,2', 'VERSION' => '22A3354']);

        $device = Device::sole();
        $response->assertStatus(301)->assertRedirect('/activate.html?enrolled='.$device->public_id);

        expect($device->user_id)->toBe($this->customer->id)
            ->and($device->udid_encrypted)->toBe(TEST_UDID)
            ->and($device->device_family)->toBe(DeviceFamily::Iphone)
            ->and($device->product)->toBe('iPhone15,2')
            ->and(DeviceRegistration::sole()->status)->toBe(DeviceRegistrationStatus::Eligible);

        $enrolled = AuditLog::where('action', 'device.enrolled')->sole();
        expect(json_encode($enrolled->after))->not->toContain('4D5E6F70')->toContain('6F70');
    });

    it('accepts each challenge once', function () {
        $challenge = enrollmentChallenge($this->customer);
        postEnrollment($challenge, ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);

        postEnrollment($challenge, ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6'])
            ->assertRedirect('/activate.html?enrollment_error=ENROLLMENT_CHALLENGE_EXPIRED');
    });

    it('rejects expired challenges', function () {
        $challenge = enrollmentChallenge($this->customer);
        $this->travel(16)->minutes();

        postEnrollment($challenge, ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6'])
            ->assertRedirect('/activate.html?enrollment_error=ENROLLMENT_CHALLENGE_EXPIRED');
        expect(Device::count())->toBe(0);
    });

    it('rejects answers that are unsigned, tampered or for another challenge', function (string $case) {
        $challenge = enrollmentChallenge($this->customer);
        $attributes = ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6'];

        $response = match ($case) {
            'unsigned plist' => postEnrollment($challenge, $attributes, '<?xml version="1.0"?><plist><dict><key>UDID</key><string>'.TEST_UDID.'</string></dict></plist>'),
            'tampered' => postEnrollment($challenge, $attributes, substr_replace(devicePayload($attributes + ['CHALLENGE' => $challenge]), 'X', 200, 1)),
            'other challenge' => postEnrollment($challenge, $attributes, devicePayload($attributes + ['CHALLENGE' => 'someone-elses'])),
            'bad udid' => postEnrollment($challenge, ['UDID' => 'not-a-udid'] + $attributes),
        };

        $response->assertRedirect('/activate.html?enrollment_error=ENROLLMENT_PAYLOAD_INVALID');
        expect(Device::count())->toBe(0)
            ->and(AuditLog::where('action', 'device.enrollment_failed')->exists())->toBeTrue();
    })->with(['unsigned plist', 'tampered', 'other challenge', 'bad udid']);

    it('refuses a device already bound to another account', function () {
        $first = subscribedCustomer();
        postEnrollment(enrollmentChallenge($first), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);

        postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6'])
            ->assertRedirect('/activate.html?enrollment_error=DEVICE_OWNED_ELSEWHERE');
    });

    it('limits devices per account but lets the same device enrol again', function () {
        postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);

        postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.7'])
            ->assertRedirect('/activate.html?enrolled='.Device::sole()->public_id);
        expect(Device::sole()->os_version)->toBe('18.7');

        postEnrollment(enrollmentChallenge($this->customer), ['UDID' => '00008030-00000000000000AA', 'PRODUCT' => 'iPhone16,1', 'VERSION' => '18.6'])
            ->assertRedirect('/activate.html?enrollment_error=DEVICE_LIMIT_REACHED');
    });

    it('keeps the device waiting until an Apple team is connected, then registers it', function () {
        postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);

        expect(Device::count())->toBe(1)->and(DeviceRegistration::count())->toBe(0);
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/storefront/status')
            ->assertJsonPath('data.stage', 'device_pending')
            ->assertJsonPath('data.next_action', 'wait_apple')
            ->assertJsonPath('data.device.udid_hint', '••••-6F70');

        connectFakeAppleTeam();
        app()->call([new SyncDeviceRegistrationsJob, 'handle']);

        expect(DeviceRegistration::sole()->status)->toBe(DeviceRegistrationStatus::Eligible);
        forgetGuards();
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/storefront/status')->assertJsonPath('data.stage', 'storefront_ready');
    });
});
