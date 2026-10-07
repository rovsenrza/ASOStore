<?php

namespace App\Services\Signing;

/**
 * Original bundle IDs an app keeps seeing after re-signing (CompatShim::originalBundleFor):
 * the operator's list in STOREFRONT_SIGNING_KEEP_BUNDLE_IDS plus a file that tools change
 * without touching .env — the device-test pipeline adds an app here when only that fixes it.
 * Patterns may use `*` wildcards; the file holds one per line.
 */
class KeptBundleIds
{
    /** @return list<string> */
    public function patterns(): array
    {
        return array_values(array_unique(array_merge(
            array_filter((array) config('storefront.signing.compat_shim.keep_bundle_ids', []), fn ($pattern) => is_string($pattern) && $pattern !== ''),
            $this->filed(),
        )));
    }

    public function matches(string $bundleIdentifier): bool
    {
        foreach ($this->patterns() as $pattern) {
            if (fnmatch($pattern, $bundleIdentifier)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> The IDs in the file (not the .env list). */
    public function filed(): array
    {
        $path = $this->path();
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', file($path) ?: []), fn (string $line) => $line !== '' && ! str_starts_with($line, '#')));
    }

    public function add(string $bundleIdentifier): void
    {
        $this->write(array_values(array_unique([...$this->filed(), $bundleIdentifier])));
    }

    public function remove(string $bundleIdentifier): void
    {
        $this->write(array_values(array_filter($this->filed(), fn (string $line) => $line !== $bundleIdentifier)));
    }

    /** @param  list<string>  $lines */
    private function write(array $lines): void
    {
        $path = $this->path();
        if ($path === '') {
            throw new \RuntimeException('storefront.signing.compat_shim.keep_bundle_ids_file is not set.');
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $temporary = $path.'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, $lines === [] ? '' : implode("\n", $lines)."\n");
        rename($temporary, $path);
    }

    private function path(): string
    {
        return (string) config('storefront.signing.compat_shim.keep_bundle_ids_file', '');
    }
}
