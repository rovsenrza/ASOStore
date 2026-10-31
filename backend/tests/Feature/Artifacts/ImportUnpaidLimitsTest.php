<?php

use App\Enums\RoleSlug;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('artifacts');
    config(['storefront.imports.unpaid_max_active' => 3, 'storefront.imports.unpaid_max_bytes' => 1000]);
});

function startImport(string $name, int $size)
{
    return test()->postJson('/api/v1/imports', ['filename' => $name.'.ipa', 'size_bytes' => $size, 'declaration_accepted' => true]);
}

it('caps how many imports a customer without a subscription can hold', function () {
    Sanctum::actingAs(userWithRoles(RoleSlug::Customer));

    foreach (['A', 'B', 'C'] as $name) {
        startImport($name, 10)->assertCreated();
    }

    startImport('D', 10)->assertStatus(409)->assertJsonPath('error.code', 'QUOTA_EXHAUSTED');
});

it('caps the total size of imports for a customer without a subscription', function () {
    Sanctum::actingAs(userWithRoles(RoleSlug::Customer));

    startImport('A', 900)->assertCreated();
    startImport('B', 200)->assertStatus(409)->assertJsonPath('error.code', 'QUOTA_EXHAUSTED');
    startImport('C', 100)->assertCreated();
});

it('does not apply the free-account cap to subscribers', function () {
    Sanctum::actingAs(subscribedCustomer());

    foreach (['A', 'B', 'C', 'D'] as $name) {
        startImport($name, 900)->assertCreated();
    }
});
