<?php

namespace App\Providers;

use App\Services\Apple\AppleIntegration;
use App\Services\Apple\AppStoreConnectIntegration;
use App\Services\Apple\DisabledAppleIntegration;
use App\Services\Apple\FakeAppleIntegration;
use App\Services\Apple\SecretStore;
use App\Services\Devices\UdidHasher;
use App\Support\Abilities;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UdidHasher::class, fn () => new UdidHasher(config('storefront.udid_hmac_key')));

        $this->app->singleton(AppleIntegration::class, fn ($app) => match (config('storefront.apple.driver')) {
            'fake' => new FakeAppleIntegration($app['cache']->store(), (int) config('storefront.apple.fake_processing_seconds'), $app->isProduction()),
            'appstoreconnect' => new AppStoreConnectIntegration($app->make(SecretStore::class), (string) config('storefront.apple.api_base_url')),
            default => new DisabledAppleIntegration,
        });
    }

    public function boot(): void
    {
        // Catch N+1 queries and typos in attribute names outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Controllers wrap resources in the {data, meta, error} envelope themselves.
        JsonResource::withoutWrapping();

        Abilities::register();
        $this->configureRateLimiting();
    }

    /**
     * FULL_PLAN §13: rate limits on authentication and other abuse-prone endpoints.
     */
    private function configureRateLimiting(): void
    {
        $email = fn (Request $request) => Str::lower(trim((string) $request->input('email')));

        RateLimiter::for('worker', fn (Request $request) => Limit::perMinute(600)->by('worker:'.$request->header('X-Runner-Key', $request->ip())));
        RateLimiter::for('installs', fn (Request $request) => Limit::perMinute(30)->by('installs:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('install-manifest', fn (Request $request) => Limit::perMinute(30)->by('manifest:'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$email($request).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(10)->by('register:'.$request->ip()));
        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute(30)->by('refresh:'.$request->ip()));
        RateLimiter::for('password-forgot', fn (Request $request) => [
            Limit::perMinute(3)->by('forgot:'.$email($request)),
            Limit::perHour(20)->by('forgot-ip:'.$request->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by('reset:'.$request->ip()));
        RateLimiter::for('activation', fn (Request $request) => Limit::perHour(10)->by('activation:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('enrollment', fn (Request $request) => Limit::perHour(20)->by('enrollment:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('enrollment-callback', fn (Request $request) => Limit::perMinute(10)->by('enrollment-callback:'.$request->ip()));
        RateLimiter::for('claims', fn (Request $request) => Limit::perMinute(10)->by('claims:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by('admin-login:'.$email($request).'|'.$request->ip()));
        RateLimiter::for('admin-totp', fn (Request $request) => [
            Limit::perMinute(5)->by('admin-totp:'.($request->hasSession() ? $request->session()->getId() : $request->ip())),
            Limit::perMinute(20)->by('admin-totp-ip:'.$request->ip()),
        ]);
    }
}
