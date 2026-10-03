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
        if (! $this->enabled() || $this->skipped($artifact)
            || (! $this->needsShim($artifact) && $this->originalBundleFor($artifact) === null)) {
            return [];
        }
        $content = $this->encoded();

        return $content === null ? [] : [['name' => self::NAME, 'content' => $content]];
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
     * The IPA's own bundle ID, when the app must keep seeing it (storefront.signing.compat_shim
     * .keep_bundle_ids) and signing gives it another one. The runner records it in Info.plist and
     * the shim answers the app's bundle-ID lookups with it.
     */
    public function originalBundleFor(AppArtifact $artifact): ?string
    {
        $original = $artifact->bundle_identifier;
        if (! $this->enabled() || ! is_string($original) || $original === $artifact->signingBundleIdentifier()) {
            return null;
        }
        foreach ((array) config('storefront.signing.compat_shim.keep_bundle_ids', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $original)) {
                return $original;
            }
        }

        return null;
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
