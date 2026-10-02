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
        if (! $this->enabled() || ! $this->needsShim($artifact)) {
            return [];
        }
        $content = $this->encoded();

        return $content === null ? [] : [['name' => self::NAME, 'content' => $content]];
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
