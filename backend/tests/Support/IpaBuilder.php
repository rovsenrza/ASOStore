<?php

namespace Tests\Support;

use CFPropertyList\CFPropertyList;
use CFPropertyList\CFTypeDetector;
use ZipArchive;

/**
 * Builds small synthetic IPAs for inspection tests (P5-OPS-01): real ZIP
 * layout, real Info.plist and minimal but well-formed Mach-O executables.
 */
class IpaBuilder
{
    public const ARM64 = 0x0100000C;

    public const X86_64 = 0x01000007;

    /** @var array<string, mixed> */
    private array $info;

    private bool $binaryPlist = false;

    private string $executable;

    /** @var array<string, string> */
    private array $extra = [];

    /** @var array<string, string> */
    private array $symlinks = [];

    private ?string $profile = null;

    public function __construct(private string $bundleId = 'com.example.demo', private string $appName = 'Demo')
    {
        $this->info = [
            'CFBundleIdentifier' => $bundleId,
            'CFBundleShortVersionString' => '1.0.0',
            'CFBundleVersion' => '42',
            'CFBundleExecutable' => $appName,
            'CFBundleName' => $appName,
            'MinimumOSVersion' => '18.0',
            'UIDeviceFamily' => [1, 2],
            'DTPlatformName' => 'iphoneos',
        ];
        $this->executable = self::machO();
    }

    public static function app(string $bundleId = 'com.example.demo'): self
    {
        return new self($bundleId);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function info(array $values): self
    {
        $this->info = array_merge($this->info, $values);

        return $this;
    }

    public function withoutInfoKey(string $key): self
    {
        unset($this->info[$key]);

        return $this;
    }

    public function binaryInfoPlist(): self
    {
        $this->binaryPlist = true;

        return $this;
    }

    public function version(string $version, string $build): self
    {
        return $this->info(['CFBundleShortVersionString' => $version, 'CFBundleVersion' => $build]);
    }

    public function executable(string $bytes): self
    {
        $this->executable = $bytes;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $entitlements
     */
    public function withExtension(string $name, string $bundleId, int $cryptId = 0, ?array $entitlements = null, ?string $profile = null): self
    {
        $root = "PlugIns/{$name}.appex/";
        $this->extra[$root.'Info.plist'] = self::plist(['CFBundleIdentifier' => $bundleId, 'CFBundleExecutable' => $name]);
        $this->extra[$root.$name] = self::machO(cryptId: $cryptId, entitlements: $entitlements);
        if ($profile !== null) {
            $this->extra[$root.'embedded.mobileprovision'] = $profile;
        }

        return $this;
    }

    public function withFramework(string $name, int $cryptId = 0): self
    {
        $root = "Frameworks/{$name}.framework/";
        $this->extra[$root.'Info.plist'] = self::plist(['CFBundleIdentifier' => "com.vendor.{$name}", 'CFBundleExecutable' => $name]);
        $this->extra[$root.$name] = self::machO(cryptId: $cryptId);

        return $this;
    }

    public function withAppFile(string $relative, string $contents): self
    {
        $this->extra[$relative] = $contents;

        return $this;
    }

    public function withSymlink(string $relative, string $target): self
    {
        $this->symlinks[$relative] = $target;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function withProfile(array $profile): self
    {
        // Real profiles are CMS envelopes around an XML plist.
        $this->profile = "0\x82\x10\x00CMS-HEADER".self::plist($profile)."\x00CMS-TRAILER";

        return $this;
    }

    /**
     * @param  array<string, string>  $rootEntries  Extra entries at archive root level.
     */
    public function build(array $rootEntries = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ipa-test');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $app = "Payload/{$this->appName}.app/";
        $zip->addEmptyDir('Payload');
        $zip->addEmptyDir(rtrim($app, '/'));
        $zip->addFromString($app.'Info.plist', self::plist($this->info, $this->binaryPlist));
        $zip->addFromString($app.$this->appName, $this->executable);
        if ($this->profile !== null) {
            $zip->addFromString($app.'embedded.mobileprovision', $this->profile);
        }
        foreach ($this->extra as $relative => $contents) {
            $zip->addFromString($app.$relative, $contents);
        }
        foreach ($this->symlinks as $relative => $target) {
            $zip->addFromString($app.$relative, $target);
            $zip->setExternalAttributesName($app.$relative, ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
        foreach ($rootEntries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function plist(array $values, bool $binary = false): string
    {
        $plist = new CFPropertyList;
        $plist->add((new CFTypeDetector(['castNumericStrings' => false]))->toCFType($values));

        return $binary ? $plist->toBinary() : $plist->toXML();
    }

    /**
     * A thin 64-bit Mach-O with build version, encryption info and a code
     * signature carrying an entitlements blob. $loads become LC_LOAD_DYLIB
     * commands the binary uses no symbol from, as an injector appends them.
     *
     * @param  array<string, mixed>|null  $entitlements
     * @param  list<string>  $loads
     */
    public static function machO(int $cpuType = self::ARM64, int $cryptId = 0, int $platform = 2, ?array $entitlements = null, array $loads = []): string
    {
        $entitlements ??= ['application-identifier' => 'ABCDE12345.com.example.demo', 'get-task-allow' => false];
        $xml = self::plist($entitlements);

        $entitlementBlob = pack('NN', 0xFADE7171, 8 + strlen($xml)).$xml;
        $signature = pack('NNN', 0xFADE0CC0, 20 + strlen($entitlementBlob), 1).pack('NN', 5, 20).$entitlementBlob;

        $buildVersion = pack('VVVVVV', 0x32, 24, $platform, 0x00120000, 0x00120000, 0);
        $encryption = pack('VVVVVV', 0x2C, 24, 0x4000, $cryptId ? 0x1000 : 0, $cryptId, 0);
        $dylibs = '';
        foreach ($loads as $name) {
            $size = (24 + strlen($name) + 1 + 7) & ~7;
            $dylibs .= str_pad(pack('VVVVVV', 0xC, $size, 24, 0, 0, 0).$name."\0", $size, "\0");
        }
        $commandsSize = strlen($buildVersion) + strlen($encryption) + strlen($dylibs) + 16;
        $signatureOffset = 32 + $commandsSize;
        $codeSignature = pack('VVVV', 0x1D, 16, $signatureOffset, strlen($signature));

        $header = pack('VVVVVVVV', 0xFEEDFACF, $cpuType, 0, 2, 3 + count($loads), $commandsSize, 0, 0);

        return $header.$buildVersion.$encryption.$dylibs.$codeSignature.$signature;
    }

    /**
     * A universal binary wrapping the given thin slices.
     *
     * @param  list<string>  $slices
     */
    public static function fat(array $slices): string
    {
        $align = 0x4000;
        $header = pack('NN', 0xCAFEBABE, count($slices));
        $body = '';
        $offset = $align;

        foreach ($slices as $slice) {
            $cpuType = unpack('V', $slice, 4)[1];
            $header .= pack('NNNNN', $cpuType, 0, $offset, strlen($slice), 14);
            $padded = str_pad($slice, (int) (ceil(strlen($slice) / $align) * $align), "\0");
            $body .= $padded;
            $offset += strlen($padded);
        }

        return str_pad($header, $align, "\0").$body;
    }
}
