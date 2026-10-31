<?php

use App\Enums\DeviceFamily;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\TeamQuota;
use App\Services\Devices\DeviceRegistrationService;
use App\Services\Quotas\QuotaService;
use App\Services\Quotas\TeamSelector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScriptedApple;

beforeEach(function () {
    ScriptedApple::install();
    $this->team = connectFakeAppleTeam();
    $this->device = Device::factory()->make(['device_family' => DeviceFamily::Iphone]);
    $this->device->setUdid(TEST_UDID);
    $this->device->enrolled_at = now();
    $this->device->save();
});

it('locks the device rows before looking for a current registration', function () {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $registration = app(DeviceRegistrationService::class)->request($this->device);

    $lock = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from `devices`') && str_contains($sql, 'for update'));
    $lookup = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from `device_registrations`'));
    expect($lock)->not->toBeFalse()
        ->and($lock)->toBeLessThan($lookup)
        ->and(app(DeviceRegistrationService::class)->request($this->device)->id)->toBe($registration->id)
        ->and(DeviceRegistration::query()->where('udid_hash', $this->device->udid_hash)->count())->toBe(1);
});

it('locks a full team and the teams it may switch to in ascending id order', function () {
    $registration = app(DeviceRegistrationService::class)->request($this->device);
    TeamQuota::query()->delete();
    // The switch target's row is older than the full team's, so its id is lower.
    $target = TeamQuota::query()->create([
        'apple_team_id' => $this->team->id, 'membership_year_id' => $registration->membership_year_id,
        'device_family' => DeviceFamily::Ipad->value, 'limit_count' => 100,
    ]);
    $full = app(QuotaService::class)->quotaFor($registration);
    $full->update(['limit_count' => 0]);

    $this->mock(TeamSelector::class, function ($mock) use ($target) {
        $mock->shouldReceive('switchQuotaIds')->andReturn([$target->id]);
        $mock->shouldReceive('handleExhausted')->once()
            ->withArgs(fn ($locked, $quota, $ids) => $ids === [$target->id, $quota->id])
            ->andReturn(QuotaService::SWITCHED);
    });
    $locks = [];
    DB::listen(function (QueryExecuted $query) use (&$locks) {
        if (str_contains($query->sql, 'from `team_quotas`') && str_contains($query->sql, 'for update')) {
            $locks[] = $query->bindings;
        }
    });

    expect(app(QuotaService::class)->reserve($registration))->toBe(QuotaService::SWITCHED)
        ->and($locks)->toBe([[$full->id], [$target->id, $full->id]]);
});
