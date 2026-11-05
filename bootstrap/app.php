<?php

use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SeoHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
        // Outermost, so even 404s raised by route-model binding get "noindex".
        $middleware->prependToGroup('web', SeoHeaders::class);

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
