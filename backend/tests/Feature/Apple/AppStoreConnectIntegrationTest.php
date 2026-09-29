<?php

use App\Enums\AppleDeviceStatus;
use App\Models\AppleCredential;
use App\Services\Apple\AppleCredentialsException;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleRetryableException;
use App\Services\Apple\AppStoreConnectIntegration;
use App\Services\Apple\SecretStore;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);
    $this->publicPem = openssl_pkey_get_details($key)['key'];

    $this->team = connectFakeAppleTeam();
    AppleCredential::create([
        'apple_team_id' => $this->team->id,
        'issuer_id' => '57246542-96fe-1a63-e053-0824d011072a',
        'key_id' => 'ABC123DEFG',
        'vault_reference' => app(SecretStore::class)->storeEncrypted('secrets/test/'.uniqid().'.p8.enc', $privatePem),
    ]);
    $this->team->load('activeCredential');
    $this->apple = new AppStoreConnectIntegration(app(SecretStore::class), 'https://api.appstoreconnect.apple.com/v1');
    $this->device = fn (string $status = 'ENABLED') => ['data' => ['type' => 'devices', 'id' => 'X9Y8Z7', 'attributes' => ['udid' => TEST_UDID, 'status' => $status, 'platform' => 'IOS']]];
});

it('authenticates with a short-lived ES256 token for the team key', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->apple->verifyCredentials($this->team);

    Http::assertSent(function (Request $request) {
        $token = substr($request->header('Authorization')[0], 7);
        [$header] = explode('.', $token);
        $claims = JWT::decode($token, new Key($this->publicPem, 'ES256'));

        return json_decode(base64_decode($header), true) === ['typ' => 'JWT', 'alg' => 'ES256', 'kid' => 'ABC123DEFG']
            && $claims->iss === '57246542-96fe-1a63-e053-0824d011072a'
            && $claims->aud === 'appstoreconnect-v1'
            && $claims->exp - $claims->iat <= 1200;
    });
});

it('enables only the capabilities an App ID is missing', function () {
    Http::fake([
        'api.appstoreconnect.apple.com/v1/bundleIds/B1/bundleIdCapabilities*' => Http::response(['data' => [
            ['type' => 'bundleIdCapabilities', 'id' => 'B1_APP_GROUPS', 'attributes' => ['capabilityType' => 'APP_GROUPS']],
        ]]),
        'api.appstoreconnect.apple.com/v1/bundleIdCapabilities' => Http::response(['data' => ['type' => 'bundleIdCapabilities', 'id' => 'B1_NE']], 201),
    ]);

    $this->apple->ensureCapabilities($this->team, 'B1', ['NETWORK_EXTENSIONS', 'APP_GROUPS']);

    // Apple refuses paging parameters on this relationship.
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.appstoreconnect.apple.com/v1/bundleIds/B1/bundleIdCapabilities');
    $posts = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => $request->method() === 'POST')->values();
    expect($posts)->toHaveCount(1)
        ->and($posts[0]['data']['attributes'])->toBe(['capabilityType' => 'NETWORK_EXTENSIONS'])
        ->and($posts[0]['data']['relationships']['bundleId']['data'])->toBe(['type' => 'bundleIds', 'id' => 'B1']);
});

it('names App IDs in Latin letters, which is all Apple accepts', function () {
    expect(AppStoreConnectIntegration::appIdName('Яндекс Пэй', 'com.ruappstore.yandex-pay'))->toBe('Yandex Pay')
        ->and(AppStoreConnectIntegration::appIdName('Яндекс Пэй Widget', 'com.ruappstore.yandex-pay.widget'))->toBe('Yandex Pay Widget')
        ->and(AppStoreConnectIntegration::appIdName('AmneziaVPN tunnel', 'x.y'))->toBe('AmneziaVPN tunnel')
        ->and(AppStoreConnectIntegration::appIdName('✓✓✓', 'org.example.app'))->toBe('Org Example App');
});

it('registers an iOS device', function () {
    Http::fake(['api.appstoreconnect.apple.com/v1/devices' => Http::response(($this->device)(), 201)]);

    $device = $this->apple->registerDevice($this->team, TEST_UDID, 'Storefront 0123456789');

    expect($device->id)->toBe('X9Y8Z7')->and($device->status)->toBe(AppleDeviceStatus::Enabled);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['data']['type'] === 'devices'
        && $request['data']['attributes'] === ['name' => 'Storefront 0123456789', 'udid' => TEST_UDID, 'platform' => 'IOS']);
});

it('finds the existing device when Apple reports a conflict', function () {
    Http::fake([
        'api.appstoreconnect.apple.com/v1/devices?*' => Http::response(['data' => [($this->device)()['data']]]),
        'api.appstoreconnect.apple.com/v1/devices' => Http::response(['errors' => [['detail' => 'already exists']]], 409),
    ]);

    expect($this->apple->registerDevice($this->team, TEST_UDID, 'x')->id)->toBe('X9Y8Z7');
});

it('maps Apple failures to retryable, credential and permanent errors', function (int $status, string $exception) {
    Http::fake(['*' => Http::response(['errors' => [['detail' => 'nope']]], $status, $status === 429 ? ['Retry-After' => '90'] : [])]);

    expect(fn () => $this->apple->getDevice($this->team, 'X9Y8Z7'))->toThrow($exception);
})->with([
    [429, AppleRetryableException::class],
    [503, AppleRetryableException::class],
    [401, AppleCredentialsException::class],
    [403, AppleCredentialsException::class],
    [422, AppleException::class],
]);

it('honours Retry-After and counts rate limits', function () {
    Cache::forget(AppStoreConnectIntegration::METRIC_429);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '90'])]);

    try {
        $this->apple->getDevice($this->team, 'X9Y8Z7');
    } catch (AppleRetryableException $e) {
        expect($e->retryAfterSeconds)->toBe(90);
    }

    expect(Cache::get(AppStoreConnectIntegration::METRIC_429))->toBe(1);
});

it('never stores the private key in the database', function () {
    expect(AppleCredential::sole()->vault_reference)->toStartWith('encrypted-file:')
        ->and(json_encode(AppleCredential::sole()->toArray()))->not->toContain('PRIVATE KEY');
});
