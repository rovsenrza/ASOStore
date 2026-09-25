<?php

use App\Enums\RoleSlug;
use App\Enums\SubscriptionStatus;
use App\Models\AppleTeam;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use CFPropertyList\CFTypeDetector;
use Database\Seeders\FakeAppleTeamSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * A user with the given roles (seeding the role table on first use).
 */
function userWithRoles(RoleSlug ...$roles): User
{
    if (Role::query()->doesntExist()) {
        (new RoleSeeder)->run();
    }

    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->whereIn('slug', array_map(fn (RoleSlug $role) => $role->value, $roles))->pluck('id'));

    return $user->load('roles');
}

/**
 * Makes the following requests look like same-origin browser requests, so
 * Sanctum gives them a session (IMPLEMENTATION_PLAN D2).
 */
function asBrowser(): TestCase
{
    return test()->withHeader('Referer', 'http://localhost');
}

/**
 * A staff browser session that has passed the TOTP step.
 */
function asStaff(User $user): TestCase
{
    return asBrowser()
        ->actingAs($user, 'web')
        ->withSession(['admin.user_id' => $user->id, 'admin.totp_verified_at' => now()->getTimestamp()]);
}

/**
 * Auth guards cache the resolved user between requests in one test; forget
 * them so the next request re-checks tokens and sessions.
 */
function forgetGuards(): void
{
    app('auth')->forgetGuards();
}

/**
 * A customer with an active subscription (past the activation step).
 */
function subscribedCustomer(): User
{
    $user = userWithRoles(RoleSlug::Customer);
    Subscription::create([
        'user_id' => $user->id,
        'plan' => 'standard',
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addYear(),
    ]);

    return $user->fresh();
}

function connectFakeAppleTeam(): AppleTeam
{
    (new FakeAppleTeamSeeder)->run();

    return AppleTeam::primary();
}

/**
 * What iOS posts back to the Profile Service URL: a DER-encoded CMS message,
 * signed by the device, wrapping a plist of the requested attributes.
 *
 * @param  array<string, string>  $attributes
 */
function devicePayload(array $attributes): string
{
    static $identity = null;
    $identity ??= (function () {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test iPhone'], $key), null, $key, 1);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return [$certificatePem, $keyPem];
    })();

    $plist = new CFPropertyList\CFPropertyList;
    $plist->add((new CFTypeDetector)->toCFType($attributes));

    $in = tempnam(sys_get_temp_dir(), 'payload-in');
    $out = tempnam(sys_get_temp_dir(), 'payload-out');
    file_put_contents($in, $plist->toXML());
    openssl_cms_sign($in, $out, $identity[0], $identity[1], [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
    $der = (string) file_get_contents($out);
    unlink($in);
    unlink($out);

    return $der;
}

/**
 * Downloads an enrollment profile as the signed-in customer and returns its challenge.
 */
function enrollmentChallenge(User $customer): string
{
    $profile = asBrowser()->actingAs($customer, 'web')->get('/api/v1/devices/enrollment-profile')->assertOk()->getContent();
    $plist = new CFPropertyList\CFPropertyList;
    $plist->parse($profile);

    return $plist->toArray()['PayloadContent']['Challenge'];
}

/**
 * Posts a device answer to the enrollment callback, as iOS would.
 *
 * @param  array<string, string>  $attributes
 */
function postEnrollment(string $challenge, array $attributes, ?string $body = null): TestResponse
{
    forgetGuards();

    return test()->call(
        'POST',
        '/api/v1/devices/enrollment/callback?'.http_build_query(['challenge' => $challenge]),
        server: ['CONTENT_TYPE' => 'application/pkcs7-signature', 'HTTP_REFERER' => ''],
        content: $body ?? devicePayload($attributes + ['CHALLENGE' => $challenge]),
    );
}

const TEST_UDID = '00008030-001A2B3C4D5E6F70';
