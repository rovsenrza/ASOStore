<?php

use App\Enums\AppleTeamStatus;
use App\Enums\RoleSlug;
use App\Models\AppleTeam;
use App\Models\Device;
use App\Models\ExternalCertificate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'instant_certs.enabled' => true, 'instant_certs.api_key' => 'test-key', 'instant_certs.base_url' => 'https://reseller.test/api/v1',
        'telegram_store.token' => 'test-token', 'telegram_store.admin_ids' => ['1000'], 'telegram_store.welcome_banner' => '',
    ]);
    $this->status = 'signed';
    $this->balance = 40;
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'reseller.test/api/v1/certificate')) {
            return Http::response(['ok' => true, 'certificates' => [['id' => 'C1', 'status' => $this->status, 'provision_valid' => $this->status === 'signed', 'expired' => false]]]);
        }
        if (str_contains($request->url(), 'reseller.test/api/v1/me')) {
            return Http::response(['ok' => true, 'balance' => $this->balance]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    });
    $user = userWithRoles(RoleSlug::Customer);
    $device = Device::factory()->make(['user_id' => $user->id]);
    $device->setUdid('00008030-0000000000000042');
    $device->save();
    $team = AppleTeam::create(['apple_team_id' => 'EXTTEAM001', 'name' => 'Ext', 'status' => AppleTeamStatus::External, 'membership_expires_at' => now()->addYear()]);
    $this->cert = ExternalCertificate::create([
        'apple_team_id' => $team->id, 'device_id' => $device->id, 'udid_hash' => $device->udid_hash, 'provider' => 'nekoo',
        'provider_certificate_id' => 'C1', 'sha1_fingerprint' => str_repeat('a', 40), 'profile_uuid' => 'P1', 'profile_encrypted' => 'x', 'status' => 'ACTIVE',
    ]);
    $this->adminTexts = fn () => collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), 'api.telegram.org') && ($request['chat_id'] ?? null) === 1000)
        ->map(fn (Request $request) => (string) ($request['text'] ?? $request['caption'] ?? ''))->values();
});

it('keeps a working certificate and tells the admins when one is revoked', function () {
    $this->artisan('instant-certs:check')->assertSuccessful();
    expect($this->cert->refresh()->status)->toBe('ACTIVE')->and(($this->adminTexts)())->toBeEmpty();

    $this->status = 'revoked';
    $this->cert->forceFill(['checked_at' => null])->save();
    $this->artisan('instant-certs:check')->assertSuccessful();
    expect($this->cert->refresh()->status)->toBe('REVOKED')
        ->and(($this->adminTexts)()->implode(' '))->toContain('сертификат отозван');
});

it('warns the admins once about a low reseller balance', function () {
    $this->balance = 2;
    $this->artisan('instant-certs:check')->assertSuccessful();
    $this->cert->forceFill(['checked_at' => null])->save();
    $this->artisan('instant-certs:check')->assertSuccessful();
    expect(($this->adminTexts)()->filter(fn ($text) => str_contains($text, 'Баланс продавца'))->count())->toBe(1);
});
