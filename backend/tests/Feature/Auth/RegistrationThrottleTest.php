<?php

use App\Providers\AppServiceProvider;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => (new RoleSeeder)->run());

function registerFrom(string $ip, int $n)
{
    return asBrowser()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register', [
        'name' => 'User '.$n,
        'email' => "user{$n}@example.com",
        'password' => 'correct horse 42',
    ]);
}

it('groups IPv4 by /24 and IPv6 by /64', function () {
    expect(AppServiceProvider::subnet('203.0.113.7'))->toBe(AppServiceProvider::subnet('203.0.113.250'))
        ->and(AppServiceProvider::subnet('203.0.113.7'))->not->toBe(AppServiceProvider::subnet('203.0.114.7'))
        ->and(AppServiceProvider::subnet('2001:db8:1:2::1'))->toBe(AppServiceProvider::subnet('2001:db8:1:2:ffff::9'))
        ->and(AppServiceProvider::subnet('2001:db8:1:2::1'))->not->toBe(AppServiceProvider::subnet('2001:db8:1:3::1'));
});

it('limits sign-ups per /24 network across different addresses', function () {
    config(['storefront.registration.per_subnet_hourly' => 3]);

    foreach (range(1, 3) as $n) {
        registerFrom("198.51.100.{$n}", $n)->assertCreated();
    }

    registerFrom('198.51.100.99', 4)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    registerFrom('198.51.101.1', 5)->assertCreated();
});

it('limits sign-ups globally', function () {
    config(['storefront.registration.global_hourly' => 2]);

    registerFrom('192.0.2.1', 1)->assertCreated();
    registerFrom('198.51.100.1', 2)->assertCreated();
    registerFrom('203.0.113.1', 3)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
