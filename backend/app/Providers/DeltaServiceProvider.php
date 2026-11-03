<?php

namespace App\Providers;

use App\Services\Signing\Delta\DeltaCoreLocator;
use App\Services\Signing\Delta\StoredDeltaCoreLocator;
use Illuminate\Support\ServiceProvider;

/**
 * Delta v3 (docs/adr/DELTA_V3.md): the composer and verifier find core blobs through DeltaCoreLocator. Registered
 * after AppServiceProvider, so this binding replaces any default bound there with singletonIf.
 */
class DeltaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeltaCoreLocator::class, StoredDeltaCoreLocator::class);
    }
}
