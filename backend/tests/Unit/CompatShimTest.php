<?php

use App\Models\AppArtifact;
use App\Services\Signing\CompatShim;

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
