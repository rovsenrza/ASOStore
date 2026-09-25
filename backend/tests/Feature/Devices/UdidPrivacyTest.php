<?php

use App\Models\AuditLog;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
 * FULL_PLAN §7: UDIDs are sensitive. Across the whole enrollment and
 * registration flow they must not reach logs, audit rows or customer APIs.
 */
it('keeps the UDID out of logs, audit records and customer responses', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    connectFakeAppleTeam();
    $customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6'])->assertStatus(301);
    forgetGuards();

    Sanctum::actingAs($customer);
    $responses = [
        $this->getJson('/api/v1/storefront/status')->getContent(),
        $this->getJson('/api/v1/devices/me')->getContent(),
        $this->getJson('/api/v1/auth/me')->getContent(),
    ];

    $fragment = substr(TEST_UDID, 9, 12);
    expect(implode("\n", $logged))->not->toContain($fragment)
        ->and(AuditLog::all()->toJson())->not->toContain($fragment)
        ->and(implode("\n", $responses))->not->toContain($fragment)
        // At rest, only the encrypted form and the HMAC exist.
        ->and(json_encode(DB::table('devices')->get()))->not->toContain($fragment);
});
