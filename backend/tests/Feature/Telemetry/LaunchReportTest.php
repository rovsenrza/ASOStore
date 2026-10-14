<?php

use App\Models\AppLaunchReport;
use App\Models\Certificate;
use App\Models\SigningProfile;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->team = connectFakeAppleTeam();
    $certificate = Certificate::query()->create([
        'apple_team_id' => $this->team->id, 'sha1_fingerprint' => str_repeat('a', 40), 'serial_number' => '1',
        'common_name' => 'iPhone Distribution: Test', 'status' => 'ACTIVE',
    ]);
    SigningProfile::query()->create([
        'apple_team_id' => $this->team->id, 'certificate_id' => $certificate->id, 'bundle_identifier' => 'com.ruappstore.tgabc.team7',
        'apple_profile_id' => 'P1', 'uuid' => (string) Str::uuid(), 'name' => 'test', 'status' => 'ACTIVE', 'content_encrypted' => 'x',
    ]);
    $this->alerts = [];
    $messenger = Mockery::mock(Messenger::class);
    $messenger->shouldReceive('notifyAdmins')->andReturnUsing(function (Screen $screen) {
        $this->alerts[] = $screen->text;
    });
    app()->instance(Messenger::class, $messenger);
});

it('stores a crash from one of our builds and tells the admins once an hour', function () {
    $crash = [
        'kind' => 'CRASHED', 'bundle' => 'com.ruappstore.tgabc.team7', 'original_bundle' => 'ru.oneme.app', 'version' => '25.1.0',
        'os' => '16.7', 'model' => 'iPhone10,6', 'shim' => '3', 'detail' => 'SIGSEGV', 'backtrace' => ['0 MAX 0x1', '1 UIKitCore 0x2'],
        'at' => now()->getTimestamp(),
    ];
    $this->postJson('/api/v1/telemetry/launch', $crash)->assertStatus(202);
    $this->postJson('/api/v1/telemetry/launch', $crash)->assertStatus(202);

    $report = AppLaunchReport::query()->latest('id')->firstOrFail();
    expect(AppLaunchReport::query()->count())->toBe(2)
        ->and($report->apple_team_id)->toBe($this->team->id)
        ->and($report->os_version)->toBe('16.7')
        ->and($report->backtrace)->toBe("0 MAX 0x1\n1 UIKitCore 0x2")
        ->and($this->alerts)->toHaveCount(1)
        ->and($this->alerts[0])->toContain('упало при запуске')->toContain('iPhone10,6');

    $this->artisan('apps:launch-reports', ['report' => $report->id])->expectsOutputToContain('UIKitCore')->assertSuccessful();
    $this->artisan('apps:launch-reports', ['--kind' => 'crashed'])->expectsOutputToContain('SIGSEGV')->assertSuccessful();
});

it('records launches quietly and drops reports for bundles we never signed', function () {
    $this->postJson('/api/v1/telemetry/launch', ['kind' => 'LAUNCHED', 'bundle' => 'com.ruappstore.tgabc.team7'])->assertStatus(202);
    $this->postJson('/api/v1/telemetry/launch', ['kind' => 'CRASHED', 'bundle' => 'com.someone.else'])->assertStatus(202);
    $this->postJson('/api/v1/telemetry/launch', ['kind' => 'NONSENSE', 'bundle' => 'com.ruappstore.tgabc.team7'])->assertStatus(422);

    expect(AppLaunchReport::query()->pluck('kind')->all())->toBe(['LAUNCHED'])
        ->and($this->alerts)->toBe([]);
});
