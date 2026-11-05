<?php

use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'platform' => EnsurePlatformAdmin::class,
            'module.enabled' => EnsureModuleEnabled::class,
            'security.policy' => EnforceSecurityPolicy::class,
        ]);

        $middleware->appendToGroup('web', IdentifyTenant::class);

        // Tenant context must exist before route-model binding resolves
        // {patient}/{invoice}/... so the BelongsToTenant scope applies and a
        // foreign tenant's id 404s instead of loading.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: IdentifyTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
