<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access to tenant modules while the active tenant is suspended.
 * Settings/logout remain reachable so users are not locked out of their
 * own account.
 */
class EnsureTenantIsActive
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();

        if ($tenant && $tenant->status === 'suspended') {
            abort(403, __('This organisation\'s account is suspended: :reason', ['reason' => $tenant->suspension_reason ?: __('contact your administrator')]));
        }

        return $next($request);
    }
}
