<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\RecordRequestMetrics;
use App\Http\Middleware\RequireStaffSession;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyWorkerSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
        then: function (): void {
            Route::prefix('api/worker/v1')
                ->name('worker.')
                ->middleware([AssignRequestId::class, 'throttle:worker', VerifyWorkerSignature::class, SubstituteBindings::class])
                ->group(base_path('routes/worker.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        // Behind a tunnel or load balancer, trust its forwarded host/scheme so generated
        // URLs (e.g. the enrollment callback inside the .mobileconfig) are correct.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->append(SecurityHeaders::class);
        $middleware->appendToGroup('api', RecordRequestMetrics::class);
        // Same-origin browser requests get session cookies + CSRF (Sanctum SPA, IMPLEMENTATION_PLAN D2).
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'staff' => RequireStaffSession::class,
            'idempotent' => Idempotent::class,
        ]);
        // Check the account and staff session before resolving route models, so
        // unauthorised callers get 403 rather than learning which IDs exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAccountActive::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequireStaffSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
