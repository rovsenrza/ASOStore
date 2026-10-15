<?php

use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\Subscription;
use App\Services\Subscriptions\DelayCompensation;
use Illuminate\Support\Carbon;

// A customer whose device registration took more than 30 minutes gets the wait and a week added.

beforeEach(function () {
    $this->team = connectFakeAppleTeam();
    $this->now = Carbon::parse('2026-10-20 12:00:00');
    $this->user = userWithRoles(RoleSlug::Customer);
    $this->endsAt = Carbon::parse('2027-10-01 10:00:00');
    $this->subscription = Subscription::create(['user_id' => $this->user->id, 'plan' => 'standard', 'status' => 'ACTIVE',
        'starts_at' => '2026-10-01 10:00:00', 'ends_at' => $this->endsAt]);

    // A registration created `$minutesAgo` minutes before "now"; enabled after `$waited` minutes when given.
    $this->register = function (int $minutesAgo, ?int $waited = null, ?Status $status = null) {
        $device = Device::factory()->create(['user_id' => $this->user->id]);
        $registration = DeviceRegistration::create([
            'device_id' => $device->id, 'apple_team_id' => $this->team->id, 'membership_year_id' => $this->team->currentMembershipYear()->id,
            'udid_hash' => bin2hex(random_bytes(32)), 'device_family' => DeviceFamily::Iphone,
            'status' => $status ?? ($waited === null ? Status::ApplePending : Status::Eligible),
        ]);
        $created = $this->now->copy()->subMinutes($minutesAgo);
        $registration->forceFill(['created_at' => $created, 'eligible_at' => $waited === null ? null : $created->copy()->addMinutes($waited)])->save();

        return $registration->refresh();
    };
    $this->ends = fn () => Subscription::find($this->subscription->id)->ends_at;
    $this->compensation = fn () => app(DelayCompensation::class);
});

it('leaves a registration that was ready within 30 minutes alone and does not look at it again', function () {
    $registration = ($this->register)(600, 30);

    $stats = ($this->compensation)()->run(now: $this->now);

    expect($stats['assessed'])->toBe(1)->and($stats['gifts'])->toBe(0)
        ->and(($this->ends)()->equalTo($this->endsAt))->toBeTrue()
        ->and($registration->refresh()->delay_assessed_at)->not->toBeNull()
        ->and($registration->delay_compensation_minutes)->toBe(0)
        ->and(AuditLog::where('action', 'subscription.extended')->count())->toBe(0);
});

it('adds the time waited and a week once the device is ready after more than 30 minutes', function () {
    $registration = ($this->register)(900, 300); // waited 5 hours

    ($this->compensation)()->run(now: $this->now);

    expect(($this->ends)()->equalTo($this->endsAt->copy()->addDays(7)->addMinutes(300)))->toBeTrue()
        ->and($registration->refresh()->delay_compensation_minutes)->toBe(300)
        ->and($registration->delay_gift_at)->not->toBeNull();
    $audit = AuditLog::where('action', 'subscription.extended')->sole();
    expect($audit->after)->toMatchArray(['days' => 7, 'delay_minutes' => 300])
        ->and($audit->reason)->toContain('7 days gift')->toContain('300 minutes waited');

    // Running again changes nothing.
    ($this->compensation)()->run(now: $this->now);
    expect(($this->ends)()->equalTo($this->endsAt->copy()->addDays(7)->addMinutes(300)))->toBeTrue();
});

it('gives the week as soon as the wait passes 30 minutes and the waited time when the device is ready', function () {
    $registration = ($this->register)(45); // still pending after 45 minutes

    ($this->compensation)()->run(now: $this->now);
    expect(($this->ends)()->equalTo($this->endsAt->copy()->addDays(7)))->toBeTrue()
        ->and($registration->refresh()->delay_gift_at)->not->toBeNull()
        ->and($registration->delay_assessed_at)->toBeNull();

    // Apple enables it 10 hours after the request: only the waited time is added now, no second week.
    $registration->forceFill(['status' => Status::Eligible, 'eligible_at' => $registration->created_at->copy()->addHours(10)])->save();
    ($this->compensation)()->run(now: $this->now);

    expect(($this->ends)()->equalTo($this->endsAt->copy()->addDays(7)->addHours(10)))->toBeTrue()
        ->and($registration->refresh()->delay_compensation_minutes)->toBe(600);
});

it('does nothing for a registration that has waited less than 30 minutes, or exactly 30', function () {
    ($this->register)(20);
    ($this->register)(30);

    ($this->compensation)()->run(now: $this->now);

    expect(($this->ends)()->equalTo($this->endsAt))->toBeTrue();
});

it('does not touch a registration that failed at Apple', function () {
    ($this->register)(500, null, Status::AppleFailed);

    ($this->compensation)()->run(now: $this->now);

    expect(($this->ends)()->equalTo($this->endsAt))->toBeTrue();
});

it('counts a dry run without changing anything', function () {
    $registration = ($this->register)(900, 300);

    $stats = ($this->compensation)()->run(dryRun: true, now: $this->now);

    expect($stats)->toMatchArray(['gifts' => 1, 'waits' => 1, 'gift_days' => 7, 'wait_minutes' => 300])
        ->and(($this->ends)()->equalTo($this->endsAt))->toBeTrue()
        ->and($registration->refresh()->delay_assessed_at)->toBeNull();
});

it('waits for a subscription instead of marking the registration done', function () {
    Subscription::query()->delete();
    $registration = ($this->register)(900, 300);

    $stats = ($this->compensation)()->run(now: $this->now);

    expect($stats['skipped'])->toBe(1)
        ->and($registration->refresh()->delay_assessed_at)->toBeNull();
});

it('can be switched off', function () {
    config(['storefront.delay_compensation.enabled' => false]);
    ($this->register)(900, 300);

    ($this->compensation)()->run(now: $this->now);

    expect(($this->ends)()->equalTo($this->endsAt))->toBeTrue();
});

it('runs from the command and is scheduled every five minutes', function () {
    ($this->register)(900, 300);

    $this->artisan('subscriptions:compensate-delays', ['--dry-run' => true])->assertSuccessful();

    $scheduled = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'subscriptions:compensate-delays'));
    expect($scheduled)->not->toBeNull()->and($scheduled->expression)->toBe('*/5 * * * *');
});
