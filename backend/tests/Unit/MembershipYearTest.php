<?php

use App\Enums\DeviceFamily;
use App\Models\AppleTeam;
use App\Models\MembershipYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
 * Membership-year boundaries (Phase 7 exit gate): a year covers
 * [starts_at, ends_at); the last second belongs to the old year and the next
 * one to the new year, so slot counts never overlap.
 */
beforeEach(function () {
    $this->team = AppleTeam::create(['apple_team_id' => 'TEAMYEAR01', 'name' => 'T', 'status' => 'ACTIVE']);
    $this->first = MembershipYear::create(['apple_team_id' => $this->team->id, 'starts_at' => '2026-01-15 00:00:00', 'ends_at' => '2027-01-15 00:00:00']);
    $this->second = MembershipYear::create(['apple_team_id' => $this->team->id, 'starts_at' => '2027-01-15 00:00:00', 'ends_at' => '2028-01-15 00:00:00']);
});

afterEach(fn () => Carbon::setTestNow());

it('uses the old year until its last second and the new year from the first second', function (string $at, ?string $expected) {
    Carbon::setTestNow($at);

    expect($this->team->currentMembershipYear()?->starts_at->toDateString())->toBe($expected);
})->with([
    'first day' => ['2026-01-15 00:00:00', '2026-01-15'],
    'last second of year one' => ['2027-01-14 23:59:59', '2026-01-15'],
    'first second of year two' => ['2027-01-15 00:00:00', '2027-01-15'],
    'after the last year' => ['2028-01-15 00:00:00', null],
    'before the first year' => ['2026-01-14 23:59:59', null],
]);

it('classifies Apple products into quota families', function (string $product, DeviceFamily $family) {
    expect(DeviceFamily::fromProduct($product))->toBe($family);
})->with([
    ['iPhone15,2', DeviceFamily::Iphone],
    ['iPad13,4', DeviceFamily::Ipad],
    ['iPod9,1', DeviceFamily::Ipod],
    ['Watch6,1', DeviceFamily::Unknown],
    ['', DeviceFamily::Unknown],
]);
