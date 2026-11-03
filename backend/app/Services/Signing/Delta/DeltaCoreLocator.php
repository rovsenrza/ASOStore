<?php

namespace App\Services\Signing\Delta;

/**
 * Finds the local copy of a delta-v2 core (docs/adr/DELTA_V3.md) by the sha256 of its blob, for
 * composing and verifying segmented entries. Bound in the container; the storage-backed implementation
 * fetches `cores/<sha256>.bin` from the `signed` disk into a local cache.
 */
interface DeltaCoreLocator
{
    /** An absolute path to a readable file holding the core blob, or null when it is not available. */
    public function localPath(string $sha256): ?string;
}
