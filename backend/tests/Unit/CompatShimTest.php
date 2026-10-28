<?php

use App\Models\AppArtifact;
use App\Services\Signing\CompatShim;
use App\Services\Signing\KeptBundleIds;
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

it('recognises relay-hook apps by the configured bundle-id patterns', function () {
    config(['storefront.signing.relay_hook.bundle_ids' => ['ru.oneme.app', 'com.vk.*']]);
    $shim = app(CompatShim::class);

    expect($shim->isRelayHook(AppArtifact::factory()->make(['bundle_identifier' => 'ru.oneme.app'])))->toBeTrue()
        ->and($shim->isRelayHook(AppArtifact::factory()->make(['bundle_identifier' => 'com.vk.messenger'])))->toBeTrue()
        ->and($shim->isRelayHook(AppArtifact::factory()->make(['bundle_identifier' => 'com.yandex.pay'])))->toBeFalse();
});

it('injects the relay + features hooks for a relay-hook app, neither for others', function () {
    $relay = tempnam(sys_get_temp_dir(), 'relay').'.dylib';
    $features = tempnam(sys_get_temp_dir(), 'feat').'.dylib';
    file_put_contents($relay, "\xCF\xFA\xED\xFE relay");
    file_put_contents($features, "\xCF\xFA\xED\xFE features");
    config([
        'storefront.signing.relay_hook.bundle_ids' => ['ru.oneme.app'],
        'storefront.signing.relay_hook.path' => $relay,
        'storefront.signing.relay_hook.features_path' => $features,
    ]);

    $names = fn (string $bundle) => array_column(
        app(CompatShim::class)->dylibsFor(AppArtifact::factory()->make(['bundle_identifier' => $bundle, 'inspection' => []])),
        'name'
    );

    expect($names('ru.oneme.app'))->toContain('MAXRelay.dylib')->toContain('MAXFeatures.dylib')
        ->and($names('com.yandex.pay'))->not->toContain('MAXRelay.dylib')->not->toContain('MAXFeatures.dylib');

    @unlink($relay);
    @unlink($features);
});

it('injects VKRelay (and no MAX features/glass) for a VK relay-hook app', function () {
    $vk = tempnam(sys_get_temp_dir(), 'vkrelay').'.dylib';
    $faceid = tempnam(sys_get_temp_dir(), 'vkface').'.dylib';
    $features = tempnam(sys_get_temp_dir(), 'feat').'.dylib';
    $glass = tempnam(sys_get_temp_dir(), 'glass').'.dylib';
    file_put_contents($vk, "\xCF\xFA\xED\xFE vk-full");
    file_put_contents($faceid, "\xCF\xFA\xED\xFE vk-faceid");
    file_put_contents($features, "\xCF\xFA\xED\xFE features");
    file_put_contents($glass, "\xCF\xFA\xED\xFE glass");
    config([
        'storefront.signing.relay_hook.bundle_ids' => ['ru.oneme.app'],
        'storefront.signing.relay_hook.vk_bundle_ids' => ['com.vk.vkclient', 'com.vk.vkclient1'],
        'storefront.signing.relay_hook.vk_path' => $vk,
        'storefront.signing.relay_hook.vk_faceid_path' => $faceid,
        'storefront.signing.relay_hook.vk_full_relay_udids' => ['00008140-00145D163C69801C'],
        'storefront.signing.relay_hook.features_path' => $features,
        'storefront.signing.relay_hook.glass_path' => $glass,
    ]);

    $dylibs = fn (string $bundle, ?string $udid = null) => app(CompatShim::class)->dylibsFor(
        AppArtifact::factory()->make(['bundle_identifier' => $bundle, 'inspection' => []]),
        $udid
    );
    $names = fn (string $bundle, ?string $udid = null) => array_column($dylibs($bundle, $udid), 'name');
    $vkContent = fn (string $bundle, ?string $udid = null) => base64_decode(
        collect($dylibs($bundle, $udid))->firstWhere('name', 'VKRelay.dylib')['content']
    );

    expect($names('com.vk.vkclient'))->toContain('VKRelay.dylib')
        ->not->toContain('MAXRelay.dylib')->not->toContain('MAXFeatures.dylib')->not->toContain('MAXGlass.dylib')
        ->and($names('com.vk.vkclient1'))->toContain('VKRelay.dylib');

    // Default (no device / ordinary customer) gets the Face-ID-only build; a configured test
    // device (and only it) gets the full notification/call relay build.
    expect($vkContent('com.vk.vkclient'))->toBe("\xCF\xFA\xED\xFE vk-faceid")
        ->and($vkContent('com.vk.vkclient', '00008130-00125D492130001C'))->toBe("\xCF\xFA\xED\xFE vk-faceid")
        ->and($vkContent('com.vk.vkclient', '00008140-00145d163c69801c'))->toBe("\xCF\xFA\xED\xFE vk-full")
        ->and($vkContent('com.vk.vkclient', '00008140-00145D163C69801C'))->toBe("\xCF\xFA\xED\xFE vk-full");

    @unlink($vk);
    @unlink($faceid);
    @unlink($features);
    @unlink($glass);
});

it('injects TGRelay (and no MAX/VK dylibs) for a Telegram relay-hook app', function () {
    $tg = tempnam(sys_get_temp_dir(), 'tgrelay').'.dylib';
    $relay = tempnam(sys_get_temp_dir(), 'relay').'.dylib';
    file_put_contents($tg, "\xCF\xFA\xED\xFE tg");
    file_put_contents($relay, "\xCF\xFA\xED\xFE relay");
    config([
        'storefront.signing.relay_hook.bundle_ids' => ['ru.oneme.app'],
        'storefront.signing.relay_hook.path' => $relay,
        'storefront.signing.relay_hook.tg_bundle_ids' => ['ph.telegra.Telegraph'],
        'storefront.signing.relay_hook.tg_path' => $tg,
    ]);
    $shim = app(CompatShim::class);
    // Telegram uses app groups, so it also gets the compat shim; it must NOT get MAX/VK dylibs.
    $tgArtifact = AppArtifact::factory()->make([
        'bundle_identifier' => 'ph.telegra.Telegraph',
        'inspection' => ['entitlements' => ['com.apple.security.application-groups' => ['group.ph.telegra.Telegraph']]],
    ]);
    $dylibs = $shim->dylibsFor($tgArtifact);
    $names = array_column($dylibs, 'name');

    expect($shim->isTgRelayHook($tgArtifact))->toBeTrue()
        ->and($names)->toContain('TGRelay.dylib')->toContain('RuStoreCompat.dylib')
        ->not->toContain('MAXRelay.dylib')->not->toContain('VKRelay.dylib')->not->toContain('MAXFeatures.dylib')
        ->and(base64_decode(collect($dylibs)->firstWhere('name', 'TGRelay.dylib')['content']))->toBe("\xCF\xFA\xED\xFE tg");

    // A non-Telegram app gets no TGRelay.
    $other = AppArtifact::factory()->make(['bundle_identifier' => 'ru.oneme.app', 'inspection' => []]);
    expect(array_column($shim->dylibsFor($other), 'name'))->not->toContain('TGRelay.dylib');

    @unlink($tg);
    @unlink($relay);
});

it('ships a real fat arm64/arm64e TGRelay dylib at the configured default path', function () {
    config(['storefront.signing.relay_hook.tg_bundle_ids' => ['ph.telegra.Telegraph'],
        'storefront.signing.relay_hook.tg_path' => base_path('../ios/tg-relay/TGRelay.dylib')]);
    $shim = app(CompatShim::class);
    $tgArtifact = AppArtifact::factory()->make(['bundle_identifier' => 'ph.telegra.Telegraph', 'inspection' => []]);
    $bytes = base64_decode(collect($shim->dylibsFor($tgArtifact))->firstWhere('name', 'TGRelay.dylib')['content']);
    expect(bin2hex(substr($bytes, 0, 4)))->toBe('cafebabe')
        ->and(strlen($bytes))->toBeGreaterThan(10000);
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

it('signs listed apps exactly as supplied, without the shim', function () {
    $groups = ['com.apple.security.application-groups' => ['group.x']];
    config(['storefront.signing.compat_shim.skip_bundle_ids' => ['com.yandex.fintech.*']]);
    $shim = app(CompatShim::class);
    $skipped = AppArtifact::factory()->make(['bundle_identifier' => 'com.yandex.fintech.bank-app', 'inspection' => ['entitlements' => $groups]]);
    $other = AppArtifact::factory()->make(['bundle_identifier' => 'ru.vk.app', 'inspection' => ['entitlements' => $groups]]);

    expect($shim->skipped($skipped))->toBeTrue()
        ->and($shim->dylibsFor($skipped))->toBe([])
        ->and($shim->dylibsFor($other))->toHaveCount(1);
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

it('names the vendor team only when it is not the signing team, and can be switched off', function () {
    $shim = app(CompatShim::class);
    $claude = AppArtifact::factory()->make(['inspection' => ['entitlements' => ['application-identifier' => 'Q6L2SF6YDW.com.anthropic.claude']]]);

    expect($shim->originalTeamFor($claude, '5CV985HSCG'))->toBe('Q6L2SF6YDW')
        // Already ours: nothing to re-prefix.
        ->and($shim->originalTeamFor($claude, 'Q6L2SF6YDW'))->toBeNull()
        // No usable original identifier: no claim about a team.
        ->and($shim->originalTeamFor(artifactWith(null), '5CV985HSCG'))->toBeNull()
        ->and($shim->originalTeamFor(artifactWith(['application-identifier' => 'not-a-team.app']), '5CV985HSCG'))->toBeNull()
        ->and($shim->originalTeamFor(artifactWith(['application-identifier' => 'q6l2sf6ydw.app']), '5CV985HSCG'))->toBeNull();

    config(['storefront.signing.rewrite_team_prefix' => false]);
    expect(app(CompatShim::class)->originalTeamFor($claude, '5CV985HSCG'))->toBeNull();
});

it('also keeps the vendor bundle ID for apps in the tool-managed list file', function () {
    $file = sys_get_temp_dir().'/keep-'.bin2hex(random_bytes(4)).'.txt';
    config(['storefront.signing.compat_shim.keep_bundle_ids' => [], 'storefront.signing.compat_shim.keep_bundle_ids_file' => $file]);
    $artifact = AppArtifact::factory()->create(['bundle_identifier' => 'com.vendor.game', 'inspection' => []]);
    $artifact->app->forceFill(['bundle_identifier' => 'com.ruappstore.tggame'])->save();
    $shim = app(CompatShim::class);

    expect($shim->originalBundleFor($artifact->refresh()))->toBeNull();
    app(KeptBundleIds::class)->add('com.vendor.game');
    expect($shim->originalBundleFor($artifact))->toBe('com.vendor.game');
    @unlink($file);
});
