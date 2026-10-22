<?php

namespace App\Services\Signing;

use App\Models\AppArtifact;
use Illuminate\Support\Facades\Log;

/**
 * The launch-compatibility shim (ios/compat-shim/RuStoreCompat.dylib) the runner injects
 * into an app at signing time. Re-signing gives the app a new bundle ID, one App Group and
 * keychain groups under our team; the app's own code still asks for the vendor's original
 * App Group and team-prefixed keychain groups, gets nothing, and quits after its launch
 * screen. The shim redirects those lookups to what the signature actually grants.
 *
 * It is injected only for apps whose entitlements declare App Groups or keychain sharing —
 * the ones that break — so apps that need neither keep the warm signing-tree cache.
 */
class CompatShim
{
    public const NAME = 'RuStoreCompat.dylib';

    public const RELAY_NAME = 'MAXRelay.dylib';

    public const VK_RELAY_NAME = 'VKRelay.dylib';

    public const FEATURES_NAME = 'MAXFeatures.dylib';

    public const GLASS_NAME = 'MAXGlass.dylib';

    private ?string $encoded = null;

    public function enabled(): bool
    {
        return (bool) config('storefront.signing.compat_shim.enabled', true) && is_file($this->path());
    }

    /**
     * The dylibs to inject for one artifact, in the runner's lease shape.
     *
     * @return list<array{name: string, content: string}>
     */
    public function dylibsFor(AppArtifact $artifact): array
    {
        $dylibs = [];
        if ($this->enabled() && ! $this->skipped($artifact)
            && ($this->needsShim($artifact) || $this->originalBundleFor($artifact) !== null)) {
            $content = $this->encoded();
            if ($content !== null) {
                $dylibs[] = ['name' => self::NAME, 'content' => $content];
            }
        }

        // The MAX notification-relay hook: ships the session to our server-side mirror, for the
        // listed apps only (storefront.signing.relay_hook.bundle_ids matched against the IPA's id).
        $relay = $this->relayDylibFor($artifact);
        if ($relay !== null) {
            $dylibs[] = $relay;
        }

        // MAXFeatures: forces on MAX's native-but-server-gated reactions / stories feature flags.
        $features = $this->featuresDylibFor($artifact);
        if ($features !== null) {
            $dylibs[] = $features;
        }

        // MAXGlass: on iOS 26+ gives MAX's custom OMUIKit.TabBar the Liquid Glass look (cosmetic).
        $glass = $this->glassDylibFor($artifact);
        if ($glass !== null) {
            $dylibs[] = $glass;
        }

        return $dylibs;
    }

    /** @return array{name: string, content: string}|null */
    private function glassDylibFor(AppArtifact $artifact): array|null
    {
        // MAX-only (OMUIKit tab bar); never injected into VK.
        if (! $this->isRelayHook($artifact) || $this->isVkRelayHook($artifact)) {
            return null;
        }
        $path = (string) config('storefront.signing.relay_hook.glass_path', base_path('../ios/max-glass/'.self::GLASS_NAME));
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('signing.glass_hook_missing', ['path' => $path]);

            return null;
        }

        return ['name' => self::GLASS_NAME, 'content' => base64_encode($bytes)];
    }

    /** @return array{name: string, content: string}|null */
    private function featuresDylibFor(AppArtifact $artifact): array|null
    {
        // MAX-only (reactions / stories feature flags); never injected into VK.
        if (! $this->isRelayHook($artifact) || $this->isVkRelayHook($artifact)) {
            return null;
        }
        $path = (string) config('storefront.signing.relay_hook.features_path', base_path('../ios/max-features/'.self::FEATURES_NAME));
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('signing.features_hook_missing', ['path' => $path]);

            return null;
        }

        return ['name' => self::FEATURES_NAME, 'content' => base64_encode($bytes)];
    }

    /**
     * Whether this app gets the MAX notification-relay hook (its IPA bundle id matches
     * storefront.signing.relay_hook.bundle_ids). Such apps are signed per device, not shared
     * across a team: the relay ties each install to a subscription by the single device UDID
     * in its provisioning profile, which a team-shared build could not carry.
     */
    public function isRelayHook(AppArtifact $artifact): bool
    {
        return $this->matchesRelayBundles($artifact, (array) config('storefront.signing.relay_hook.bundle_ids', []))
            || $this->isVkRelayHook($artifact);
    }

    /**
     * Whether this app gets the VK notification-relay hook (VKRelay.dylib) instead of MAX's. VK
     * uses the same per-device server-side mirror, but its session lives in the keychain (not in
     * OKMessenger frames), so it ships a different dylib and none of the MAX-only feature/glass
     * dylibs. Matched against storefront.signing.relay_hook.vk_bundle_ids.
     */
    public function isVkRelayHook(AppArtifact $artifact): bool
    {
        return $this->matchesRelayBundles($artifact, (array) config('storefront.signing.relay_hook.vk_bundle_ids', []));
    }

    /** @param array<int, mixed> $patterns */
    private function matchesRelayBundles(AppArtifact $artifact, array $patterns): bool
    {
        $bundle = $artifact->bundle_identifier;
        if (! is_string($bundle)) {
            return false;
        }

        return (bool) array_filter($patterns, fn ($p) => is_string($p) && $p !== '' && fnmatch($p, $bundle));
    }

    /** @return array{name: string, content: string}|null */
    private function relayDylibFor(AppArtifact $artifact): array|null
    {
        if (! $this->isRelayHook($artifact)) {
            return null;
        }
        if ($this->isVkRelayHook($artifact)) {
            $name = self::VK_RELAY_NAME;
            $path = (string) config('storefront.signing.relay_hook.vk_path', base_path('../ios/vk-relay/'.self::VK_RELAY_NAME));
        } else {
            $name = self::RELAY_NAME;
            $path = (string) config('storefront.signing.relay_hook.path', base_path('../ios/max-relay/'.self::RELAY_NAME));
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            Log::warning('signing.relay_hook_missing', ['path' => $path, 'name' => $name]);

            return null;
        }

        return ['name' => $name, 'content' => base64_encode($bytes)];
    }

    /**
     * The team ID the vendor built the app with (the prefix of its original application-identifier),
     * when it differs from the signing team. The runner re-prefixes Info.plist values that start with
     * it, so a keychain group the app reads from its own Info.plist falls under our `<TEAM>.*` access.
     */
    public function originalTeamFor(AppArtifact $artifact, string $signingTeam): ?string
    {
        if (! (bool) config('storefront.signing.rewrite_team_prefix', true)) {
            return null;
        }
        $applicationIdentifier = $artifact->inspection['entitlements']['application-identifier'] ?? null;
        if (! is_string($applicationIdentifier) || preg_match('/^([A-Z0-9]{10})\./', $applicationIdentifier, $match) !== 1) {
            return null;
        }

        return $match[1] === $signingTeam ? null : $match[1];
    }

    /**
     * Apps signed exactly as supplied, without the shim (storefront.signing.compat_shim
     * .skip_bundle_ids), e.g. a package that already carries its own sideload fix.
     */
    public function skipped(AppArtifact $artifact): bool
    {
        $bundle = $artifact->bundle_identifier;
        if (! is_string($bundle)) {
            return false;
        }
        foreach ((array) config('storefront.signing.compat_shim.skip_bundle_ids', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $bundle)) {
                return true;
            }
        }

        return false;
    }

    /** An app sharing through an App Group or a keychain group loses it on re-sign. */
    public function needsShim(AppArtifact $artifact): bool
    {
        $entitlements = $artifact->inspection['entitlements'] ?? null;
        if (! is_array($entitlements)) {
            return false;
        }

        return array_key_exists('com.apple.security.application-groups', $entitlements)
            || array_key_exists('keychain-access-groups', $entitlements);
    }

    /**
     * The IPA's own bundle ID, when the app must keep seeing it (KeptBundleIds) and signing
     * gives it another one. The runner records it in Info.plist and the shim answers the
     * app's bundle-ID lookups with it.
     */
    public function originalBundleFor(AppArtifact $artifact): ?string
    {
        $original = $artifact->bundle_identifier;
        if (! $this->enabled() || ! is_string($original) || $original === $artifact->signingBundleIdentifier()) {
            return null;
        }

        return app(KeptBundleIds::class)->matches($original) ? $original : null;
    }

    private function encoded(): ?string
    {
        if ($this->encoded !== null) {
            return $this->encoded;
        }
        $bytes = @file_get_contents($this->path());
        if ($bytes === false || $bytes === '') {
            Log::warning('signing.compat_shim_missing', ['path' => $this->path()]);

            return null;
        }

        return $this->encoded = base64_encode($bytes);
    }

    private function path(): string
    {
        return (string) config('storefront.signing.compat_shim.path', base_path('../ios/compat-shim/'.self::NAME));
    }
}
