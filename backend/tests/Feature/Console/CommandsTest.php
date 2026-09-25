<?php

use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\AppleTeam;
use App\Models\AuditLog;

it('issues activation codes as an admin and prints them once', function () {
    userWithRoles(RoleSlug::Admin);

    $this->artisan('activation:issue', ['--count' => 2, '--days' => 30, '--note' => 'CLI'])
        ->expectsOutputToContain('-')
        ->assertSuccessful();

    expect(ActivationCode::count())->toBe(2)
        ->and(ActivationCode::first()->note)->toBe('CLI')
        ->and(AuditLog::where('action', 'activation_code.batch_created')->exists())->toBeTrue();
});

it('refuses to issue codes without an admin', function () {
    $this->artisan('activation:issue')->assertFailed();
});

it('resets an authenticator from the command line with an audit record', function () {
    $admin = userWithRoles(RoleSlug::Admin);
    $admin->forceFill(['totp_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 'totp_confirmed_at' => now()])->save();

    $this->artisan('admin:reset-totp', ['email' => $admin->email, '--reason' => 'Lost phone'])->assertSuccessful();

    expect($admin->fresh()->totp_secret)->toBeNull()
        ->and(AuditLog::where('action', 'user.totp_reset')->sole()->reason)->toBe('Lost phone');
});

it('connects a fake Apple team as primary', function () {
    $this->artisan('apple:connect', ['team-id' => 'fake000002', '--name' => 'Local'])->assertSuccessful();

    expect(AppleTeam::primary())
        ->apple_team_id->toBe('FAKE000002')
        ->status->value->toBe('ACTIVE')
        ->and(AppleTeam::primary()->currentMembershipYear())->not->toBeNull();
});

it('requires credentials to connect a real App Store Connect team', function () {
    config(['storefront.apple.driver' => 'appstoreconnect']);

    $this->artisan('apple:connect', ['team-id' => 'A1B2C3D4E5'])->assertFailed();
});
