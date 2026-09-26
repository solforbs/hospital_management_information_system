<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant for an authenticated request.
 *
 * The tenant is never taken from client-supplied input. It comes from the
 * authenticated user's verified memberships: either a tenant explicitly
 * selected earlier in the session (and re-validated against membership on
 * every request) or the user's default/only tenant.
 */
class IdentifyTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($request, $user);

        if ($tenant) {
            $this->context->set($tenant);
        }

        return $next($request);
    }

    protected function resolveTenant(Request $request, $user): ?Tenant
    {
        $selectedId = $request->session()->get('current_tenant_id');

        $memberships = $user->tenants()->wherePivot('status', 'active')->get();

        if ($memberships->isEmpty()) {
            return null;
        }

        if ($selectedId) {
            $selected = $memberships->firstWhere('id', $selectedId);

            if ($selected) {
                return $selected;
            }
        }

        $default = $memberships->firstWhere('pivot.is_default', true);

        $tenant = $default ?? $memberships->first();

        $request->session()->put('current_tenant_id', $tenant->id);

        return $tenant;
    }
}
