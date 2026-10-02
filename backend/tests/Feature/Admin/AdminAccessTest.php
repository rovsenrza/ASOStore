<?php

use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\AppVersion;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Every admin API route except the sign-in steps, with a concrete URI.
 *
 * @return list<array{0: string, 1: string}>
 */
function adminRoutes(): array
{
    $public = ['api.admin.auth.login', 'api.admin.auth.totp', 'api.admin.auth.logout'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/') && ! in_array($route->getName(), $public, true))
        ->map(fn ($route) => [collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first(), '/'.$route->uri()])
        ->values()
        ->all();
}

function concrete(string $uri): string
{
    return strtr($uri, [
        '{user}' => User::query()->value('public_id') ?? 'x',
        '{activationCode}' => ActivationCode::query()->value('public_id') ?? 'x',
        '{device}' => Device::query()->value('public_id') ?? 'x',
        '{app}' => CatalogApp::query()->value('public_id') ?? 'x',
        '{screenshot}' => 'x',
        '{version}' => AppVersion::query()->value('public_id') ?? 'x',
        '{category}' => AppCategory::query()->value('public_id') ?? 'x',
        '{publisher}' => AppPublisher::query()->value('public_id') ?? 'x',
        '{upload}' => 'x',
        '{artifact}' => 'x',
        '{document}' => 'x',
        '{job}' => 'x',
        '{runner}' => 'x',
        '{installation}' => 'x',
        '{team}' => 'x',
        '{eligibility}' => 'x',
        '{assignment}' => 'x',
        '{ticket}' => 'x',
        '{number}' => '0',
    ]);
}

it('covers every admin route', function () {
    expect(adminRoutes())->toHaveCount(74);
});

it('turns away guests and customers on every admin route', function () {
    $customer = userWithRoles(RoleSlug::Customer);

    foreach (adminRoutes() as [$method, $uri]) {
        forgetGuards();
        $this->json($method, concrete($uri))->assertUnauthorized();

        forgetGuards();
        asBrowser()->actingAs($customer, 'web')->json($method, concrete($uri))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
});

it('limits each staff role to its abilities', function (RoleSlug $role, array $allowed) {
    $staff = userWithRoles($role);
    $admin = userWithRoles(RoleSlug::Admin);
    ActivationCode::query()->create([
        'batch_id' => strtolower((string) Str::ulid()), 'code_hash' => hash('sha256', 'x'), 'code_hint' => 'XXXX', 'created_by' => $admin->id,
    ]);

    $device = Device::factory()->create();
    $app = CatalogApp::factory()->create();
    $job = PipelineJob::factory()->create();
    $runner = Runner::create(['key_id' => 'rk_probe', 'name' => 'probe', 'secret_encrypted' => 'x']);
    $probes = [
        'catalog.view' => ['GET', '/api/v1/admin/apps'],
        'catalog.manage' => ['PATCH', '/api/v1/admin/apps/'.$app->public_id],
        'artifacts.view' => ['GET', '/api/v1/admin/uploads/x'],
        'artifacts.manage' => ['POST', '/api/v1/admin/uploads'],
        'jobs.view' => ['GET', '/api/v1/admin/jobs'],
        'jobs.manage' => ['POST', '/api/v1/admin/jobs/'.$job->public_id.'/retry'],
        'installations.view' => ['GET', '/api/v1/admin/installations'],
        'teams.view' => ['GET', '/api/v1/admin/apple-teams'],
        'teams.manage' => ['PATCH', '/api/v1/admin/runners/'.$runner->public_id],
        'devices.view' => ['GET', '/api/v1/admin/devices'],
        'devices.manage' => ['POST', '/api/v1/admin/devices/'.$device->public_id.'/sync'],
        'devices.reveal-udid' => ['POST', '/api/v1/admin/devices/'.$device->public_id.'/reveal-udid'],
        'users.view' => ['GET', '/api/v1/admin/users'],
        'users.manage' => ['PUT', '/api/v1/admin/users/'.$admin->public_id.'/roles'],
        'activation-codes.view' => ['GET', '/api/v1/admin/activation-codes'],
        'activation-codes.manage' => ['POST', '/api/v1/admin/activation-codes'],
        'audit.view' => ['GET', '/api/v1/admin/audit-logs'],
    ];

    foreach ($probes as $ability => [$method, $uri]) {
        forgetGuards();
        $status = asStaff($staff)->json($method, $uri, [])->status();

        if (in_array($ability, $allowed, true)) {
            expect($status)->not->toBe(403, "{$role->value} should be allowed {$ability}");
        } else {
            expect($status)->toBe(403, "{$role->value} should be denied {$ability}");
        }
    }
})->with([
    'support' => [RoleSlug::Support, ['users.view', 'activation-codes.view', 'audit.view', 'devices.view', 'catalog.view', 'artifacts.view', 'jobs.view', 'installations.view']],
    'catalog manager' => [RoleSlug::CatalogManager, ['audit.view', 'catalog.view', 'catalog.manage', 'artifacts.view', 'artifacts.manage', 'jobs.view', 'jobs.manage', 'installations.view']],
    'admin' => [RoleSlug::Admin, ['users.view', 'users.manage', 'activation-codes.view', 'activation-codes.manage', 'audit.view', 'catalog.view', 'catalog.manage', 'artifacts.view', 'artifacts.manage', 'jobs.view', 'jobs.manage', 'installations.view', 'teams.view', 'teams.manage', 'devices.view', 'devices.manage', 'devices.reveal-udid']],
]);
