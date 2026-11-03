<?php

namespace App\Services\Signing\Delta;

/** The default until cores are stored: no core is available, so packs that need one are not served. */
final class NullDeltaCoreLocator implements DeltaCoreLocator
{
    public function localPath(string $sha256): ?string
    {
        return null;
    }
}
