<?php

namespace App\Services\Apple;

use DateTimeImmutable;

/**
 * A provisioning profile as Apple returns it. $content is the base64
 * .mobileprovision, which lists device UDIDs: store it encrypted.
 */
final readonly class AppleProfile
{
    public function __construct(
        public string $id,
        public string $uuid,
        public string $name,
        public string $content,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
