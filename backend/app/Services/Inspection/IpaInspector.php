<?php

namespace App\Services\Inspection;

use App\Enums\ArtifactStatus;
use CFPropertyList\CFPropertyList;
use DateTimeInterface;
use Throwable;
use ZipArchive;

/**
 * Technical inspection of an uploaded IPA (IMPLEMENTATION_PLAN §5.7).
 *
 * Everything in the archive is untrusted: sizes, paths and binary offsets are
 * checked before use, and nothing is extracted to disk except single
 * executables into private temporary files. Technical inspection is not
 * provenance certification; a passing IPA still goes to human review.
 */
class IpaInspector
{
    public const VERSION = 1;

    /** Entries Apple tooling may place next to Payload/. */
    private const ALLOWED_TOP_LEVEL = [
        'Payload', 'SwiftSupport', 'Symbols', 'BCSymbolMaps', 'META-INF', 'WatchKitSupport', 'WatchKitSupport2',
        'MessagesApplicationExtensionSupport', 'iTunesMetadata.plist', 'iTunesArtwork', 'iTunesArtwork@2x',
    ];

    private const PLATFORMS = [1 => 'macos', 2 => 'ios', 6 => 'maccatalyst', 7 => 'ios_simulator'];

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        private readonly MachOReader $machO,
        private readonly MalwareScanner $scanner,
    ) {}

    public function inspect(string $path): InspectionResult
    {
        $report = ['inspector_version' => self::VERSION, 'compatibility_issues' => []];

        try {
            $this->run($path, $report);
        } catch (InspectionFailure $failure) {
            $report['failure'] = [
                'code' => $failure->reason,
                'message' => $failure->getMessage(),
                'details' => $failure->details,
            ];

            return new InspectionResult($failure->outcome, $failure->reason, $report);
        } finally {
            foreach ($this->temporaryFiles as $file) {
                @unlink($file);
            }
            $this->temporaryFiles = [];
        }

        return new InspectionResult(ArtifactStatus::ProvenanceReview, null, $report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function run(string $path, array &$report): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw InspectionFailure::invalid('INVALID_ARCHIVE', 'The file is not a readable ZIP archive.');
        }

        try {
            $entries = $this->entries($zip, $report);
            $appRoot = $this->appRoot($entries);

            $info = $this->readPlist($zip, $entries, $appRoot.'Info.plist', 'INFO_PLIST_MISSING');
            $bundle = $this->bundleMetadata($info, rtrim($appRoot, '/'));
            $report['bundle'] = $bundle;

            $nested = $this->nestedBundles($zip, $entries, $appRoot);
            $report['nested_bundles'] = array_map(
                fn (array $item) => array_intersect_key($item, array_flip(['path', 'type', 'bundle_identifier'])),
                $nested,
            );

            $binaries = [['path' => $appRoot.$bundle['executable'], 'role' => 'main', 'bundle_identifier' => $bundle['bundle_identifier']]];
            foreach ($nested as $item) {
                if ($item['executable'] !== null) {
                    $binaries[] = ['path' => $item['executable'], 'role' => $item['type'], 'bundle_identifier' => $item['bundle_identifier']];
                }
            }

            $report['binaries'] = [];
            $mainSlices = [];
            foreach ($binaries as $binary) {
                $slices = $this->readBinary($zip, $entries, $binary['path'], $binary['role'] === 'main');
                $report['binaries'][] = [
                    'path' => $binary['path'],
                    'role' => $binary['role'],
                    'architectures' => array_map(fn (MachOSlice $slice) => $slice->arch, $slices),
                    'encrypted' => false,
                    'platform' => self::platformName($slices[0]->platform),
                    'min_os' => $slices[0]->minOs,
                ];

                foreach ($slices as $slice) {
                    if ($slice->isEncrypted()) {
                        // FULL_PLAN §1.2: store-encrypted binaries are never accepted.
                        $report['binaries'][array_key_last($report['binaries'])]['encrypted'] = true;
                        throw InspectionFailure::rejected('ENCRYPTED_BINARY', 'The IPA contains an encrypted (App Store) binary.', [
                            'path' => $binary['path'],
                            'architecture' => $slice->arch,
                        ]);
                    }
                }

                if ($binary['role'] === 'main') {
                    $mainSlices = $slices;
                } elseif ($binary['role'] === 'extension') {
                    // Each extension is provisioned with its own capabilities (ProfileProvisioner).
                    foreach ($report['nested_bundles'] as $index => $item) {
                        if ($item['type'] === 'extension' && str_starts_with($binary['path'], $item['path'].'/')) {
                            $report['nested_bundles'][$index]['entitlements'] = $this->entitlements($slices);
                        }
                    }
                }
            }

            $report['entitlements'] = $this->entitlements($mainSlices);
            $report['embedded_profile'] = $this->embeddedProfile($zip, $entries, $appRoot.'embedded.mobileprovision');
            $report['compatibility_issues'] = $this->compatibilityIssues($bundle, $mainSlices, $nested);
        } finally {
            $zip->close();
        }

        $scan = $this->scanner->scan($path);
        $report['malware_scan'] = $scan;
        if ($scan['status'] === MalwareScanner::INFECTED) {
            throw new InspectionFailure(ArtifactStatus::Quarantined, 'MALWARE_DETECTED', 'The malware scanner flagged this file.', [
                'signature' => $scan['signature'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Validates the central directory and returns entries keyed by name.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array{size: int, compressed: int, directory: bool}>
     */
    private function entries(ZipArchive $zip, array &$report): array
    {
        $limits = config('storefront.inspection');
        if ($zip->numFiles < 1) {
            throw InspectionFailure::invalid('EMPTY_ARCHIVE', 'The archive is empty.');
        }
        if ($zip->numFiles > $limits['max_entries']) {
            throw InspectionFailure::invalid('TOO_MANY_ENTRIES', 'The archive has too many entries.', ['entries' => $zip->numFiles]);
        }

        $entries = [];
        $folded = [];
        $total = 0;
        $compressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw InspectionFailure::invalid('INVALID_ARCHIVE', 'An archive entry cannot be read.');
            }

            $name = (string) $stat['name'];
            self::assertSafePath($name);

            $key = mb_strtolower(rtrim($name, '/'));
            if (isset($folded[$key])) {
                // Case-insensitive file systems would merge these into one file.
                throw InspectionFailure::invalid('DUPLICATE_ENTRY', 'The archive contains the same path twice.', ['path' => $name]);
            }
            $folded[$key] = true;

            if ($stat['encryption_method'] !== 0) {
                throw InspectionFailure::invalid('ENCRYPTED_ARCHIVE', 'Password-protected archives are not accepted.');
            }

            $top = explode('/', $name, 2)[0];
            if (! in_array($top, self::ALLOWED_TOP_LEVEL, true)) {
                throw InspectionFailure::invalid('UNEXPECTED_ENTRY', 'The archive contains files outside Payload/.', ['path' => $name]);
            }

            $size = (int) $stat['size'];
            $packed = (int) $stat['comp_size'];
            if ($size > $limits['ratio_min_bytes'] && $size / max($packed, 1) > $limits['max_compression_ratio']) {
                throw InspectionFailure::invalid('COMPRESSION_RATIO', 'An entry expands far beyond its compressed size.', ['path' => $name]);
            }

            $total += $size;
            $compressed += $packed;
            if ($total > $limits['max_uncompressed_bytes']) {
                throw InspectionFailure::invalid('ARCHIVE_TOO_LARGE', 'The unpacked archive would be too large.');
            }

            $this->assertSafeSymlink($zip, $i, $name);
            $entries[$name] = ['size' => $size, 'compressed' => $packed, 'directory' => str_ends_with($name, '/')];
        }

        if ($total > $limits['ratio_min_bytes'] && $total / max($compressed, 1) > $limits['max_compression_ratio']) {
            throw InspectionFailure::invalid('COMPRESSION_RATIO', 'The archive expands far beyond its compressed size.');
        }

        $report['archive'] = [
            'entries' => count($entries),
            'uncompressed_bytes' => $total,
            'compressed_bytes' => $compressed,
            // Present in App Store downloads; a signal for the provenance reviewer.
            'itunes_metadata' => isset($entries['iTunesMetadata.plist']),
        ];

        return $entries;
    }

    private static function assertSafePath(string $name): void
    {
        $unsafe = $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1;

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            $unsafe = $unsafe || $segment === '' || $segment === '.' || $segment === '..';
        }

        if ($unsafe) {
            throw InspectionFailure::invalid('UNSAFE_PATH', 'The archive contains an unsafe path.', ['path' => mb_substr($name, 0, 255)]);
        }
    }

    private function assertSafeSymlink(ZipArchive $zip, int $index, string $name): void
    {
        $system = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $system, $attributes, ZipArchive::FL_UNCHANGED)
            || $system !== ZipArchive::OPSYS_UNIX
            || (($attributes >> 16) & 0170000) !== 0120000) {
            return;
        }

        $target = $zip->getFromIndex($index, 1024);
        $depth = count(explode('/', $name)) - 1;
        if (! is_string($target) || $target === '' || str_starts_with($target, '/')) {
            throw InspectionFailure::invalid('UNSAFE_SYMLINK', 'The archive contains a symbolic link outside the app.', ['path' => $name]);
        }

        foreach (explode('/', $target) as $segment) {
            $depth += match ($segment) {
                '..' => -1,
                '', '.' => 0,
                default => 1,
            };
            if ($depth < 1) {
                throw InspectionFailure::invalid('UNSAFE_SYMLINK', 'The archive contains a symbolic link outside the app.', ['path' => $name]);
            }
        }
    }

    /**
     * @param  array<string, array{size: int, compressed: int, directory: bool}>  $entries
     */
    private function appRoot(array $entries): string
    {
        $apps = [];
        foreach (array_keys($entries) as $name) {
            $parts = explode('/', $name);
            if ($parts[0] === 'Payload' && isset($parts[2]) && $parts[1] !== '') {
                $apps[$parts[1]] = true;
            }
        }

        $apps = array_keys($apps);
        if (count($apps) !== 1 || ! str_ends_with($apps[0], '.app')) {
            throw InspectionFailure::invalid(
                count($apps) > 1 ? 'MULTIPLE_APP_BUNDLES' : 'NO_APP_BUNDLE',
                'The archive must contain exactly one Payload/*.app bundle.',
                ['found' => array_slice($apps, 0, 10)],
            );
        }

        return 'Payload/'.$apps[0].'/';
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{path: string, bundle_identifier: string, version: string, build_number: string, min_ios_version: string, executable: string, name: string|null, device_families: list<int>, platform: string|null}
     */
    private function bundleMetadata(array $info, string $path): array
    {
        $identifier = self::scalar($info['CFBundleIdentifier'] ?? null);
        $version = self::scalar($info['CFBundleShortVersionString'] ?? null);
        $build = self::scalar($info['CFBundleVersion'] ?? null);
        $minimum = self::scalar($info['MinimumOSVersion'] ?? null);
        $executable = self::scalar($info['CFBundleExecutable'] ?? null);

        $invalid = [];
        if ($identifier === null || strlen($identifier) > 155 || preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $identifier) !== 1) {
            $invalid[] = 'CFBundleIdentifier';
        }
        foreach (['CFBundleShortVersionString' => $version, 'CFBundleVersion' => $build] as $key => $value) {
            if ($value === null || strlen($value) > 32 || preg_match('/^[0-9][0-9A-Za-z.\-]*$/', $value) !== 1) {
                $invalid[] = $key;
            }
        }
        if ($minimum === null || preg_match('/^\d{1,3}(\.\d{1,3}){0,2}$/', $minimum) !== 1) {
            $invalid[] = 'MinimumOSVersion';
        }
        if ($executable === null || strlen($executable) > 255 || str_contains($executable, '/') || $executable === '..' || $executable === '.') {
            $invalid[] = 'CFBundleExecutable';
        }

        if ($invalid !== []) {
            throw InspectionFailure::invalid('INVALID_METADATA', 'Info.plist is missing required values.', ['keys' => $invalid]);
        }

        $families = $info['UIDeviceFamily'] ?? [1];
        $families = array_values(array_filter(
            array_map('intval', is_array($families) ? $families : [$families]),
            fn (int $family) => $family > 0,
        ));

        $platform = self::scalar($info['DTPlatformName'] ?? null);

        /** @var string $identifier @var string $version @var string $build @var string $minimum @var string $executable */
        return [
            'path' => $path,
            'bundle_identifier' => $identifier,
            'version' => $version,
            'build_number' => $build,
            'min_ios_version' => $minimum,
            'executable' => $executable,
            'name' => self::scalar($info['CFBundleDisplayName'] ?? $info['CFBundleName'] ?? null),
            'device_families' => $families,
            'platform' => $platform,
        ];
    }

    /**
     * Extensions, frameworks, dylibs, watch apps and App Clips directly inside the app.
     *
     * @param  array<string, array{size: int, compressed: int, directory: bool}>  $entries
     * @return list<array{path: string, type: string, bundle_identifier: string|null, executable: string|null}>
     */
    private function nestedBundles(ZipArchive $zip, array $entries, string $appRoot): array
    {
        $patterns = [
            'extension' => '#^'.preg_quote($appRoot, '#').'(PlugIns|Extensions)/([^/]+\.appex)/#',
            'framework' => '#^'.preg_quote($appRoot, '#').'(Frameworks)/([^/]+\.framework)/#',
            'watch_app' => '#^'.preg_quote($appRoot, '#').'(Watch)/([^/]+\.app)/#',
            'app_clip' => '#^'.preg_quote($appRoot, '#').'(AppClips)/([^/]+\.app)/#',
        ];

        $found = [];
        foreach (array_keys($entries) as $name) {
            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $name, $match) === 1) {
                    $found[$appRoot.$match[1].'/'.$match[2]] = $type;
                }
            }
            if (preg_match('#^'.preg_quote($appRoot, '#').'Frameworks/[^/]+\.dylib$#', $name) === 1) {
                $found[$name] = 'dylib';
            }
        }
        ksort($found);

        $bundles = [];
        foreach ($found as $path => $type) {
            if ($type === 'dylib') {
                $bundles[] = ['path' => $path, 'type' => $type, 'bundle_identifier' => null, 'executable' => $path];

                continue;
            }

            $info = $this->readPlist($zip, $entries, $path.'/Info.plist', 'INVALID_NESTED_BUNDLE');
            $executable = self::scalar($info['CFBundleExecutable'] ?? null)
                ?? ($type === 'framework' ? basename($path, '.framework') : null);
            if ($executable === null || str_contains($executable, '/') || $executable === '..') {
                throw InspectionFailure::invalid('INVALID_NESTED_BUNDLE', 'A nested bundle has no executable.', ['path' => $path]);
            }

            $executablePath = $path.'/'.$executable;
            $bundles[] = [
                'path' => $path,
                'type' => $type,
                'bundle_identifier' => self::scalar($info['CFBundleIdentifier'] ?? null),
                // Resource-only frameworks have no binary; anything else must have one.
                'executable' => isset($entries[$executablePath]) || $type !== 'framework' ? $executablePath : null,
            ];
        }

        return $bundles;
    }

    /**
     * @param  array<string, array{size: int, compressed: int, directory: bool}>  $entries
     * @return non-empty-list<MachOSlice>
     */
    private function readBinary(ZipArchive $zip, array $entries, string $path, bool $main): array
    {
        if (! isset($entries[$path]) || $entries[$path]['directory']) {
            throw InspectionFailure::invalid('EXECUTABLE_MISSING', $main ? 'The app executable is missing.' : 'A nested executable is missing.', ['path' => $path]);
        }

        $file = $this->extract($zip, $path, (int) config('storefront.inspection.max_binary_bytes'));

        try {
            $slices = $this->machO->read($file);
        } catch (InvalidMachO $e) {
            throw InspectionFailure::invalid('INVALID_EXECUTABLE', 'An executable is not a valid Mach-O file.', [
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);
        } finally {
            @unlink($file);
        }

        if ($slices === []) {
            throw InspectionFailure::invalid('INVALID_EXECUTABLE', 'An executable has no architectures.', ['path' => $path]);
        }

        return $slices;
    }

    /**
     * @param  list<MachOSlice>  $slices
     * @return array<string, mixed>|null
     */
    private function entitlements(array $slices): ?array
    {
        foreach ($slices as $slice) {
            if ($slice->isArm64() && $slice->entitlements !== null) {
                return $slice->entitlements;
            }
        }

        return $slices[0]->entitlements ?? null;
    }

    /**
     * Summary of embedded.mobileprovision. Device lists are counted, never stored.
     *
     * @param  array<string, array{size: int, compressed: int, directory: bool}>  $entries
     * @return array<string, mixed>|null
     */
    private function embeddedProfile(ZipArchive $zip, array $entries, string $path): ?array
    {
        if (! isset($entries[$path])) {
            return null;
        }

        $data = $this->read($zip, $path, (int) config('storefront.inspection.max_plist_bytes'));
        $start = strpos($data, '<?xml');
        $end = strpos($data, '</plist>');
        if ($start === false || $end === false || $end < $start) {
            return ['readable' => false];
        }

        try {
            $plist = new CFPropertyList;
            $plist->parse(substr($data, $start, $end - $start + strlen('</plist>')));
            $profile = $plist->toArray();
        } catch (Throwable) {
            return ['readable' => false];
        }

        if (! is_array($profile)) {
            return ['readable' => false];
        }

        $devices = $profile['ProvisionedDevices'] ?? null;
        $taskAllow = (bool) ($profile['Entitlements']['get-task-allow'] ?? false);
        $type = match (true) {
            (bool) ($profile['ProvisionsAllDevices'] ?? false) => 'ENTERPRISE',
            is_array($devices) && $taskAllow => 'DEVELOPMENT',
            is_array($devices) => 'AD_HOC',
            default => 'APP_STORE',
        };
        $expires = self::date($profile['ExpirationDate'] ?? null);

        return [
            'readable' => true,
            'name' => self::scalar($profile['Name'] ?? null),
            'type' => $type,
            'team_identifier' => self::scalar(is_array($profile['TeamIdentifier'] ?? null) ? ($profile['TeamIdentifier'][0] ?? null) : null),
            'app_id_name' => self::scalar($profile['AppIDName'] ?? null),
            'created_at' => self::date($profile['CreationDate'] ?? null),
            'expires_at' => $expires,
            'expired' => $expires !== null && strtotime($expires) < time(),
            'provisioned_device_count' => is_array($devices) ? count($devices) : null,
        ];
    }

    /**
     * Findings that do not stop review but block publishing until resolved
     * (checked again at COMPATIBILITY_CHECK).
     *
     * @param  array{bundle_identifier: string, device_families: list<int>, platform: string|null}  $bundle
     * @param  list<MachOSlice>  $slices
     * @param  list<array{path: string, type: string, bundle_identifier: string|null, executable: string|null}>  $nested
     * @return list<array{code: string, message: string, path?: string}>
     */
    private function compatibilityIssues(array $bundle, array $slices, array $nested): array
    {
        $issues = [];

        if (! array_filter($slices, fn (MachOSlice $slice) => $slice->isArm64())) {
            $issues[] = ['code' => 'ARM64_MISSING', 'message' => 'The app has no arm64 slice.'];
        }
        if (array_filter($slices, fn (MachOSlice $slice) => $slice->platform === 7) || $bundle['platform'] === 'iphonesimulator') {
            $issues[] = ['code' => 'SIMULATOR_BUILD', 'message' => 'This is a Simulator build and cannot run on a device.'];
        }
        if (! in_array(1, $bundle['device_families'], true)) {
            $issues[] = ['code' => 'IPHONE_UNSUPPORTED', 'message' => 'The app does not declare iPhone support.'];
        }

        foreach ($nested as $item) {
            if ($item['type'] === 'watch_app') {
                $issues[] = ['code' => 'WATCH_APP_UNSUPPORTED', 'message' => 'Embedded watch apps are not supported.', 'path' => $item['path']];
            }
            if ($item['type'] === 'app_clip') {
                $issues[] = ['code' => 'APP_CLIP_UNSUPPORTED', 'message' => 'Embedded App Clips are not supported.', 'path' => $item['path']];
            }
            if (in_array($item['type'], ['extension', 'watch_app', 'app_clip'], true)
                && ! str_starts_with((string) $item['bundle_identifier'], $bundle['bundle_identifier'].'.')) {
                $issues[] = ['code' => 'NESTED_BUNDLE_ID_MISMATCH', 'message' => 'A nested bundle ID is not prefixed by the app bundle ID.', 'path' => $item['path']];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, array{size: int, compressed: int, directory: bool}>  $entries
     * @return array<string, mixed>
     */
    private function readPlist(ZipArchive $zip, array $entries, string $path, string $missingCode): array
    {
        if (! isset($entries[$path])) {
            throw InspectionFailure::invalid($missingCode, 'A required Info.plist is missing.', ['path' => $path]);
        }

        $data = $this->read($zip, $path, (int) config('storefront.inspection.max_plist_bytes'));

        try {
            $plist = new CFPropertyList;
            $plist->parse($data);
            $values = $plist->toArray();
        } catch (Throwable) {
            $values = null;
        }

        if (! is_array($values)) {
            throw InspectionFailure::invalid('INVALID_INFO_PLIST', 'Info.plist is not a valid property list.', ['path' => $path]);
        }

        return $values;
    }

    private function read(ZipArchive $zip, string $name, int $limit): string
    {
        $file = $this->extract($zip, $name, $limit);

        try {
            return (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Streams one entry into a private temporary file, enforcing the size
     * limit on the bytes actually produced rather than the declared size.
     */
    private function extract(ZipArchive $zip, string $name, int $limit): string
    {
        $stat = $zip->statName($name, ZipArchive::FL_UNCHANGED);
        if ($stat === false) {
            throw InspectionFailure::invalid('INVALID_ARCHIVE', 'An archive entry cannot be read.', ['path' => $name]);
        }
        if ((int) $stat['size'] > $limit) {
            throw InspectionFailure::invalid('ENTRY_TOO_LARGE', 'An archive entry is larger than allowed.', ['path' => $name]);
        }

        $stream = $zip->getStream($name);
        $file = tempnam(sys_get_temp_dir(), 'ipa');
        if ($stream === false || $file === false) {
            throw InspectionFailure::invalid('INVALID_ARCHIVE', 'An archive entry cannot be read.', ['path' => $name]);
        }
        $this->temporaryFiles[] = $file;
        chmod($file, 0600);

        $out = fopen($file, 'wb');
        $written = 0;

        try {
            while ($out !== false && ! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw InspectionFailure::invalid('INVALID_ARCHIVE', 'An archive entry is corrupt.', ['path' => $name]);
                }
                $written += strlen($chunk);
                if ($written > $limit || $written > (int) $stat['size']) {
                    throw InspectionFailure::invalid('ENTRY_TOO_LARGE', 'An archive entry expands beyond its declared size.', ['path' => $name]);
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($stream);
            if ($out !== false) {
                fclose($out);
            }
        }

        if ($written !== (int) $stat['size']) {
            throw InspectionFailure::invalid('INVALID_ARCHIVE', 'An archive entry is corrupt.', ['path' => $name]);
        }

        return $file;
    }

    private static function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp());
        }

        return is_int($value) || is_float($value) ? gmdate('Y-m-d\TH:i:s\Z', (int) $value) : null;
    }

    private static function platformName(?int $platform): ?string
    {
        return $platform === null ? null : (self::PLATFORMS[$platform] ?? 'platform_'.$platform);
    }
}
