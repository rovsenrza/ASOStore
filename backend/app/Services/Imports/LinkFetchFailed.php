<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * A link import could not be downloaded. The message is shown to the customer as is, so it is
 * Russian and never carries internals (addresses, curl errors). Permanent failures (a bad link,
 * a private address, not an IPA, too large) are not retried.
 */
final class LinkFetchFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = true)
    {
        parent::__construct($message);
    }

    public static function retryable(string $message): self
    {
        return new self($message, permanent: false);
    }
}
