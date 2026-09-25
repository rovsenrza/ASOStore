<?php

namespace App\Services\Inspection;

use CFPropertyList\CFPropertyList;
use Throwable;

/**
 * Minimal Mach-O reader for IPA inspection (IMPLEMENTATION_PLAN §5.7).
 *
 * Reads only headers, load commands and the entitlements blob of each slice;
 * every offset from the file is bounds-checked because the file is untrusted.
 */
class MachOReader
{
    private const FAT_MAGIC = 0xCAFEBABE;

    private const FAT_MAGIC_64 = 0xCAFEBABF;

    private const MH_MAGIC = 0xFEEDFACE;

    private const MH_MAGIC_64 = 0xFEEDFACF;

    private const LC_CODE_SIGNATURE = 0x1D;

    private const LC_ENCRYPTION_INFO = 0x21;

    private const LC_VERSION_MIN_IPHONEOS = 0x25;

    private const LC_ENCRYPTION_INFO_64 = 0x2C;

    private const LC_BUILD_VERSION = 0x32;

    private const CS_SUPERBLOB = 0xFADE0CC0;

    private const CS_ENTITLEMENTS = 0xFADE7171;

    private const CSSLOT_ENTITLEMENTS = 5;

    private const MAX_SLICES = 16;

    private const MAX_LOAD_COMMANDS_BYTES = 16 * 1024 * 1024;

    private const MAX_SIGNATURE_BYTES = 64 * 1024 * 1024;

    /** @var resource */
    private $handle;

    private int $fileSize = 0;

    /**
     * @return list<MachOSlice>
     *
     * @throws InvalidMachO
     */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidMachO('Cannot open the executable.');
        }

        $this->handle = $handle;
        $this->fileSize = (int) filesize($path);

        try {
            $magic = $this->u32be(0);

            if ($magic === self::FAT_MAGIC || $magic === self::FAT_MAGIC_64) {
                return $this->readFat($magic === self::FAT_MAGIC_64);
            }

            return [$this->readSlice(0, $this->fileSize)];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<MachOSlice>
     */
    private function readFat(bool $is64): array
    {
        $count = $this->u32be(4);
        if ($count < 1 || $count > self::MAX_SLICES) {
            throw new InvalidMachO('Unexpected number of architectures.');
        }

        $slices = [];
        for ($i = 0; $i < $count; $i++) {
            if ($is64) {
                $entry = 8 + $i * 32;
                $offset = $this->u64be($entry + 8);
                $size = $this->u64be($entry + 16);
            } else {
                $entry = 8 + $i * 20;
                $offset = $this->u32be($entry + 8);
                $size = $this->u32be($entry + 12);
            }

            if ($offset <= 0 || $size <= 0 || $offset + $size > $this->fileSize) {
                throw new InvalidMachO('Architecture slice lies outside the file.');
            }

            $slices[] = $this->readSlice($offset, $size);
        }

        return $slices;
    }

    private function readSlice(int $start, int $size): MachOSlice
    {
        $magic = $this->u32le($start);
        $headerSize = match ($magic) {
            self::MH_MAGIC_64 => 32,
            self::MH_MAGIC => 28,
            default => throw new InvalidMachO('Not a little-endian Mach-O file.'),
        };

        $cpuType = $this->u32le($start + 4);
        $cpuSubtype = $this->u32le($start + 8) & 0x00FFFFFF;
        $commandCount = $this->u32le($start + 16);
        $commandsSize = $this->u32le($start + 20);

        if ($commandsSize > self::MAX_LOAD_COMMANDS_BYTES || $headerSize + $commandsSize > $size) {
            throw new InvalidMachO('Load commands exceed the slice.');
        }

        $commands = $this->bytes($start + $headerSize, $commandsSize);
        $cryptId = null;
        $platform = null;
        $minOs = null;
        $entitlements = null;
        $cursor = 0;

        for ($i = 0; $i < $commandCount; $i++) {
            if ($cursor + 8 > $commandsSize) {
                throw new InvalidMachO('Truncated load command.');
            }

            $command = self::unpack('V', $commands, $cursor);
            $commandSize = self::unpack('V', $commands, $cursor + 4);
            if ($commandSize < 8 || $cursor + $commandSize > $commandsSize) {
                throw new InvalidMachO('Malformed load command size.');
            }

            switch ($command) {
                case self::LC_ENCRYPTION_INFO:
                case self::LC_ENCRYPTION_INFO_64:
                    if ($commandSize < 20) {
                        throw new InvalidMachO('Malformed encryption command.');
                    }
                    // Any encrypted range makes the slice encrypted.
                    $cryptId = max($cryptId ?? 0, self::unpack('V', $commands, $cursor + 16));
                    break;
                case self::LC_BUILD_VERSION:
                    if ($commandSize >= 16) {
                        $platform = self::unpack('V', $commands, $cursor + 8);
                        $minOs = self::version(self::unpack('V', $commands, $cursor + 12));
                    }
                    break;
                case self::LC_VERSION_MIN_IPHONEOS:
                    if ($commandSize >= 12) {
                        $platform ??= 2;
                        $minOs ??= self::version(self::unpack('V', $commands, $cursor + 8));
                    }
                    break;
                case self::LC_CODE_SIGNATURE:
                    if ($commandSize >= 16) {
                        $entitlements = $this->readEntitlements(
                            $start,
                            $size,
                            self::unpack('V', $commands, $cursor + 8),
                            self::unpack('V', $commands, $cursor + 12),
                        );
                    }
                    break;
            }

            $cursor += $commandSize;
        }

        return new MachOSlice(self::archName($cpuType, $cpuSubtype), $cryptId, $platform, $minOs, $entitlements);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readEntitlements(int $sliceStart, int $sliceSize, int $offset, int $size): ?array
    {
        if ($size < 12 || $size > self::MAX_SIGNATURE_BYTES || $offset + $size > $sliceSize) {
            throw new InvalidMachO('Code signature lies outside the slice.');
        }

        $blob = $this->bytes($sliceStart + $offset, $size);
        if (self::unpack('N', $blob, 0) !== self::CS_SUPERBLOB) {
            return null;
        }

        $count = self::unpack('N', $blob, 8);
        if (12 + $count * 8 > $size) {
            throw new InvalidMachO('Malformed code signature index.');
        }

        for ($i = 0; $i < $count; $i++) {
            $type = self::unpack('N', $blob, 12 + $i * 8);
            $blobOffset = self::unpack('N', $blob, 16 + $i * 8);
            if ($type !== self::CSSLOT_ENTITLEMENTS) {
                continue;
            }
            if ($blobOffset + 8 > $size || self::unpack('N', $blob, $blobOffset) !== self::CS_ENTITLEMENTS) {
                throw new InvalidMachO('Malformed entitlements blob.');
            }

            $length = self::unpack('N', $blob, $blobOffset + 4);
            if ($length < 8 || $blobOffset + $length > $size) {
                throw new InvalidMachO('Entitlements blob exceeds the signature.');
            }

            try {
                $plist = new CFPropertyList;
                $plist->parse(substr($blob, $blobOffset + 8, $length - 8));
                $values = $plist->toArray();
            } catch (Throwable) {
                throw new InvalidMachO('Entitlements are not a valid property list.');
            }

            return is_array($values) ? $values : null;
        }

        return null;
    }

    private static function archName(int $cpuType, int $subtype): string
    {
        return match ($cpuType) {
            0x0100000C => $subtype === 2 ? 'arm64e' : 'arm64',
            0x0200000C => 'arm64_32',
            12 => 'armv7',
            0x01000007 => 'x86_64',
            7 => 'i386',
            default => sprintf('cpu_%x', $cpuType),
        };
    }

    private static function version(int $packed): string
    {
        $patch = $packed & 0xFF;
        $version = sprintf('%d.%d', $packed >> 16, ($packed >> 8) & 0xFF);

        return $patch > 0 ? "{$version}.{$patch}" : $version;
    }

    private function bytes(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->fileSize) {
            throw new InvalidMachO('Read beyond the end of the file.');
        }
        if ($length === 0) {
            return '';
        }

        fseek($this->handle, $offset);
        $data = fread($this->handle, $length);
        if ($data === false || strlen($data) !== $length) {
            throw new InvalidMachO('Truncated file.');
        }

        return $data;
    }

    private function u32be(int $offset): int
    {
        return self::unpack('N', $this->bytes($offset, 4), 0);
    }

    private function u32le(int $offset): int
    {
        return self::unpack('V', $this->bytes($offset, 4), 0);
    }

    private function u64be(int $offset): int
    {
        return self::unpack('J', $this->bytes($offset, 8), 0);
    }

    private static function unpack(string $format, string $data, int $offset): int
    {
        $width = $format === 'J' ? 8 : 4;
        if ($offset < 0 || $offset + $width > strlen($data)) {
            throw new InvalidMachO('Read beyond the end of a structure.');
        }

        return (int) unpack($format, $data, $offset)[1];
    }
}
