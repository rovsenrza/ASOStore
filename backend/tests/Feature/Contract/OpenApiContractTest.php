<?php

use App\Enums\ErrorCode;
use App\Enums\InstallStateStatus;
use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OpenApiContract;

/*
| Keeps docs/api/openapi.yaml, the shared examples and the live API in step
| (IMPLEMENTATION_PLAN D11). The iOS suite decodes the same example files.
*/

dataset('examples', [
    'health' => ['health.json', 'HealthResponse'],
    'feed' => ['storefront-feed.json', 'FeedResponse'],
    'status signed out' => ['storefront-status-signed-out.json', 'StorefrontStatusResponse'],
    'apps list' => ['apps-list.json', 'AppListResponse'],
    'app detail' => ['app-detail.json', 'AppDetailResponse'],
    'app versions' => ['app-versions.json', 'VersionListResponse'],
    'not found' => ['error-not-found.json', 'ErrorResponse'],
    'validation' => ['error-validation.json', 'ErrorResponse'],
    'invalid credentials' => ['error-invalid-credentials.json', 'ErrorResponse'],
    'status device required' => ['storefront-status-device-required.json', 'StorefrontStatusResponse'],
    'tokens' => ['auth-tokens.json', 'TokenResponse'],
    'me' => ['auth-me.json', 'MeResponse'],
    'redeem' => ['activation-redeem.json', 'RedeemResponse'],
    'admin me' => ['admin-me.json', 'AdminMeResponse'],
    'admin users' => ['admin-users.json', 'AdminUserListResponse'],
    'admin activation codes' => ['admin-activation-codes.json', 'ActivationCodeListResponse'],
    'admin audit logs' => ['admin-audit-logs.json', 'AuditLogListResponse'],
    'my devices' => ['devices-me.json', 'DeviceListResponse'],
    'status ready' => ['storefront-status-ready.json', 'StorefrontStatusResponse'],
    'claim redeem' => ['storefront-claim-redeem.json', 'ClaimRedeemResponse'],
    'admin devices' => ['admin-devices.json', 'AdminDeviceListResponse'],
    'app detail support urls' => ['app-detail.json', 'AppDetailResponse'],
    'feed games' => ['storefront-feed-games.json', 'FeedResponse'],
    'feed apps' => ['storefront-feed-apps.json', 'FeedResponse'],
    'installation preparing' => ['installation-preparing.json', 'InstallationResponse'],
    'installation ready' => ['installation-ready.json', 'InstallationResponse'],
    'install link' => ['install-link.json', 'InstallLinkResponse'],
    'library' => ['library.json', 'LibraryResponse'],
]);

it('keeps every shared example valid against its schema', function (string $file, string $schema) {
    expect(OpenApiContract::errors(file_get_contents(OpenApiContract::examplesPath($file)), $schema))->toBe([]);
})->with('examples');

it('references only example files that exist', function () {
    preg_match_all('/externalValue:\s*(\S+)\s*}/', file_get_contents(OpenApiContract::specPath()), $matches);

    foreach (array_unique($matches[1]) as $relative) {
        expect(file_exists(dirname(OpenApiContract::specPath()).'/'.$relative))->toBeTrue("Missing {$relative}");
    }
});

it('serves responses that match the contract', function () {
    $this->seed(DatabaseSeeder::class);
    $app = CatalogApp::query()->visibleToCustomers()->firstOrFail();

    $cases = [
        ['/api/v1/health', 'HealthResponse'],
        ['/api/v1/storefront/feed', 'FeedResponse'],
        ['/api/v1/storefront/status', 'StorefrontStatusResponse'],
        ['/api/v1/apps?per_page=5', 'AppListResponse'],
        ['/api/v1/apps?q=zzz-no-match', 'AppListResponse'],
        ["/api/v1/apps/{$app->public_id}", 'AppDetailResponse'],
        ["/api/v1/apps/{$app->public_id}/versions", 'VersionListResponse'],
        ['/api/v1/apps/01j0000000000000000000000z', 'ErrorResponse'],
        ['/api/v1/apps?per_page=0', 'ErrorResponse'],
    ];

    foreach ($cases as [$uri, $schema]) {
        expect(OpenApiContract::errors($this->getJson($uri)->getContent(), $schema))->toBe([], $uri);
    }
});

it('serves auth, activation and admin responses that match the contract', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', AdminUserSeeder::EMAIL)->sole();
    $check = fn ($response, string $schema) => expect(OpenApiContract::errors($response->getContent(), $schema))->toBe([], $schema);

    // Native app
    $customer = userWithRoles(RoleSlug::Customer);
    $check($this->postJson('/api/v1/auth/tokens', ['email' => $customer->email, 'password' => 'wrong']), 'ErrorResponse');
    $tokens = $this->postJson('/api/v1/auth/tokens', ['email' => $customer->email, 'password' => 'password', 'device_name' => 'iPhone']);
    $check($tokens, 'TokenResponse');
    $check($refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens->json('data.refresh_token')]), 'TokenResponse');
    forgetGuards();
    $bearer = ['Authorization' => 'Bearer '.$refreshed->json('data.access_token')];

    // Admin
    asStaff($admin);
    $check($this->getJson('/api/v1/admin/auth/me'), 'AdminMeResponse');
    $batch = $this->postJson('/api/v1/admin/activation-codes', ['count' => 2, 'duration_days' => 30, 'note' => 'Контракт']);
    $check($batch, 'ActivationCodeBatchResponse');
    $check($this->getJson('/api/v1/admin/activation-codes'), 'ActivationCodeListResponse');
    $check($this->postJson('/api/v1/admin/activation-codes/'.ActivationCode::query()->latest('id')->value('public_id').'/revoke', ['reason' => 'test']), 'ActivationCodeResponse');
    $check($this->getJson('/api/v1/admin/users'), 'AdminUserListResponse');
    $check($this->getJson("/api/v1/admin/users/{$customer->public_id}"), 'AdminUserDetailResponse');
    $check($this->putJson("/api/v1/admin/users/{$customer->public_id}/roles", ['roles' => ['customer'], 'reason' => 'test']), 'AdminUserResponse');
    $check($this->getJson('/api/v1/admin/audit-logs'), 'AuditLogListResponse');
    $check($this->postJson('/api/v1/admin/auth/logout'), 'EmptyResponse');
    forgetGuards();

    // Customer with the bearer token
    $this->flushSession();
    $check($this->postJson('/api/v1/activation/redeem', ['code' => $batch->json('data.codes.0')], $bearer), 'RedeemResponse');
    $check($this->getJson('/api/v1/auth/me', $bearer), 'MeResponse');
    $check($this->getJson('/api/v1/storefront/status', $bearer), 'StorefrontStatusResponse');
    $check($this->postJson('/api/v1/auth/logout', [], $bearer), 'EmptyResponse');
    $check($this->postJson('/api/v1/auth/password/forgot', ['email' => $customer->email]), 'AcceptedResponse');

    // Browser registration and the staff sign-in first step
    $check(asBrowser()->postJson('/api/v1/auth/register', ['name' => 'Анна', 'email' => 'anna@example.com', 'password' => 'long enough 1']), 'MeResponse');
    $check($this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong']), 'ErrorResponse');
});

it('serves device, enrollment and claim responses that match the contract', function () {
    connectFakeAppleTeam();
    $customer = subscribedCustomer();
    $check = fn ($response, string $schema) => expect(OpenApiContract::errors($response->getContent(), $schema))->toBe([], $schema);

    Sanctum::actingAs($customer);
    $check($this->getJson('/api/v1/storefront/status'), 'StorefrontStatusResponse');
    $check($this->getJson('/api/v1/devices/me'), 'DeviceListResponse');
    $check($this->postJson('/api/v1/storefront/claims'), 'ErrorResponse');
    forgetGuards();

    postEnrollment(enrollmentChallenge($customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6'])->assertStatus(301);
    forgetGuards();

    Sanctum::actingAs($customer);
    $check($this->getJson('/api/v1/storefront/status'), 'StorefrontStatusResponse');
    $check($this->getJson('/api/v1/devices/me'), 'DeviceListResponse');
    $claim = $this->postJson('/api/v1/storefront/claims');
    $check($claim, 'ClaimResponse');
    forgetGuards();
    $check($this->postJson('/api/v1/storefront/claims/redeem', ['code' => $claim->json('data.code')]), 'ClaimRedeemResponse');
    $check($this->postJson('/api/v1/storefront/claims/redeem', ['code' => 'used-or-unknown']), 'ErrorResponse');

    $device = Device::sole();
    asStaff(userWithRoles(RoleSlug::Admin));
    $check($this->getJson('/api/v1/admin/devices'), 'AdminDeviceListResponse');
    $check($this->getJson("/api/v1/admin/devices/{$device->public_id}"), 'AdminDeviceDetailResponse');
    $check($this->postJson("/api/v1/admin/devices/{$device->public_id}/reveal-udid", ['reason' => 'contract']), 'RevealUdidResponse');
    $check($this->postJson("/api/v1/admin/devices/{$device->public_id}/sync"), 'QueuedResponse');
});

it('serves admin catalog responses that match the contract', function () {
    $manager = userWithRoles(RoleSlug::CatalogManager);
    $category = AppCategory::factory()->create();
    $publisher = AppPublisher::factory()->create();
    $check = fn ($response, string $schema) => expect(OpenApiContract::errors($response->getContent(), $schema))->toBe([], $schema);

    asStaff($manager);
    $check($this->getJson('/api/v1/admin/apps'), 'AdminAppListResponse');
    $check($this->getJson('/api/v1/admin/categories'), 'AdminCategoryListResponse');
    $check($this->getJson('/api/v1/admin/publishers'), 'AdminPublisherListResponse');

    $created = $this->postJson('/api/v1/admin/apps', [
        'name' => 'Контрактное приложение',
        'category_id' => $category->public_id,
        'publisher_id' => $publisher->public_id,
        'source_type' => 'OWN_BUILD',
    ]);
    $check($created, 'AdminAppDetailResponse');
    $id = $created->json('data.id');

    $check($this->getJson("/api/v1/admin/apps/{$id}"), 'AdminAppDetailResponse');
    $check($this->patchJson("/api/v1/admin/apps/{$id}", ['visibility' => 'PUBLISHED', 'reason' => 'contract']), 'AdminAppDetailResponse');
    $check($this->postJson("/api/v1/admin/apps/{$id}/versions", ['version' => '1.0.0', 'build_number' => '1']), 'VersionResponse');
    $check($this->postJson('/api/v1/admin/categories', ['title' => 'Контракт']), 'AdminCategoryResponse');
    $check($this->postJson('/api/v1/admin/publishers', ['name' => 'Контракт Паблишер']), 'AdminPublisherResponse');
    $check($this->deleteJson("/api/v1/admin/apps/{$id}", ['reason' => 'contract']), 'DeleteAppResponse');
    $check($this->postJson("/api/v1/admin/apps/{$id}/restore"), 'AdminAppDetailResponse');
});

it('lists exactly the error codes the backend can emit', function () {
    $documented = OpenApiContract::document()['components']['schemas']['ErrorCode']['enum'];

    expect($documented)->toBe(array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases()));
});

it('lists exactly the install states the backend can emit', function () {
    $documented = OpenApiContract::document()['components']['schemas']['InstallState']['properties']['status']['enum'];

    expect($documented)->toBe(array_map(fn (InstallStateStatus $status) => $status->value, InstallStateStatus::cases()));
});
