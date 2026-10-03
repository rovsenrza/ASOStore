<?php

use App\Models\AppArtifact;
use App\Services\Signing\CompatShim;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The artifact factory creates its app and version rows, so this needs a migrated database.
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dylib = tempnam(sys_get_temp_dir(), 'shim').'.dylib';
    file_put_contents($this->dylib, "\xCF\xFA\xED\xFE fake macho");
    config(['storefront.signing.compat_shim.enabled' => true, 'storefront.signing.compat_shim.path' => $this->dylib]);
});

afterEach(fn () => @unlink($this->dylib));

function artifactWith(?array $entitlements): AppArtifact
{
    return AppArtifact::factory()->make(['inspection' => $entitlements === null ? [] : ['entitlements' => $entitlements]]);
}

it('injects the shim only for apps that share through an App Group or keychain', function () {
    $shim = app(CompatShim::class);

    expect($shim->needsShim(artifactWith(['com.apple.security.application-groups' => ['group.x']])))->toBeTrue()
        ->and($shim->needsShim(artifactWith(['keychain-access-groups' => ['TEAM.*']])))->toBeTrue()
        ->and($shim->needsShim(artifactWith(['application-identifier' => 'TEAM.app', 'get-task-allow' => false])))->toBeFalse()
        ->and($shim->needsShim(artifactWith(null)))->toBeFalse();
});

it('returns the dylib bytes in the lease shape, or nothing when not needed', function () {
    $shim = app(CompatShim::class);
    $dylibs = $shim->dylibsFor(artifactWith(['com.apple.security.application-groups' => ['group.x']]));

    expect($dylibs)->toHaveCount(1)
        ->and($dylibs[0]['name'])->toBe('RuStoreCompat.dylib')
        ->and(base64_decode($dylibs[0]['content']))->toBe(file_get_contents($this->dylib))
        ->and($shim->dylibsFor(artifactWith(['application-identifier' => 'TEAM.app'])))->toBe([]);
});

it('injects nothing when the shim is disabled or its file is missing', function () {
    $groups = ['com.apple.security.application-groups' => ['group.x']];

    config(['storefront.signing.compat_shim.enabled' => false]);
    expect(app(CompatShim::class)->dylibsFor(artifactWith($groups)))->toBe([]);

    config(['storefront.signing.compat_shim.enabled' => true, 'storefront.signing.compat_shim.path' => '/no/such/shim.dylib']);
    expect(app(CompatShim::class)->enabled())->toBeFalse()
        ->and(app(CompatShim::class)->dylibsFor(artifactWith($groups)))->toBe([]);
});

it('ships a real fat arm64/arm64e dylib at the configured default path', function () {
    // The committed binary the runner injects in production.
    config(['storefront.signing.compat_shim.path' => base_path('../ios/compat-shim/RuStoreCompat.dylib')]);
    $shim = app(CompatShim::class);
    expect($shim->enabled())->toBeTrue();
    $bytes = base64_decode($shim->dylibsFor(artifactWith(['com.apple.security.application-groups' => ['group.x']]))[0]['content']);
    // Mach-O fat magic (0xCAFEBABE) big-endian.
    expect(bin2hex(substr($bytes, 0, 4)))->toBe('cafebabe')
        ->and(strlen($bytes))->toBeGreaterThan(10000);
});

it('keeps the vendor bundle ID only for listed apps that are signed under another one', function () {
    config(['storefront.signing.compat_shim.keep_bundle_ids' => ['ru.yandex.mobile.music', 'ru.kinopoisk.*']]);
    $shim = app(CompatShim::class);
    $listed = AppArtifact::factory()->create(['bundle_identifier' => 'ru.yandex.mobile.music', 'inspection' => []]);
    $listed->app->forceFill(['bundle_identifier' => 'com.ruappstore.tgmusic'])->save();
    $wildcard = AppArtifact::factory()->create(['bundle_identifier' => 'ru.kinopoisk.tv', 'inspection' => []]);
    $wildcard->app->forceFill(['bundle_identifier' => 'com.ruappstore.tgkino'])->save();
    $other = AppArtifact::factory()->create(['bundle_identifier' => 'ru.vk.app', 'inspection' => []]);
    $other->app->forceFill(['bundle_identifier' => 'com.ruappstore.tgvk'])->save();

    expect($shim->originalBundleFor($listed->refresh()))->toBe('ru.yandex.mobile.music')
        ->and($shim->originalBundleFor($wildcard->refresh()))->toBe('ru.kinopoisk.tv')
        ->and($shim->originalBundleFor($other->refresh()))->toBeNull()
        // A listed app gets the shim even without App Groups, so its lookups can be answered.
        ->and($shim->dylibsFor($listed))->toHaveCount(1)
        ->and($shim->dylibsFor($other))->toBe([]);

    config(['storefront.signing.compat_shim.keep_bundle_ids' => []]);
    expect(app(CompatShim::class)->originalBundleFor($listed))->toBeNull();
});
