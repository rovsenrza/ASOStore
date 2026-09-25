<?php

namespace App\Services\Inspection;

/**
 * One architecture slice of a Mach-O executable, as far as inspection needs it.
 */
final readonly class MachOSlice
{
    /**
     * @param  int|null  $cryptId  LC_ENCRYPTION_INFO(_64).cryptid, or null when the command is absent.
     * @param  int|null  $platform  LC_BUILD_VERSION platform (2 = iOS, 7 = iOS Simulator).
     * @param  array<string, mixed>|null  $entitlements  From the code signature, when present.
     */
    public function __construct(
        public string $arch,
        public ?int $cryptId,
        public ?int $platform,
        public ?string $minOs,
        public ?array $entitlements,
    ) {}

    public function isEncrypted(): bool
    {
        return $this->cryptId !== null && $this->cryptId !== 0;
    }

    public function isArm64(): bool
    {
        return $this->arch === 'arm64' || $this->arch === 'arm64e';
    }
}
