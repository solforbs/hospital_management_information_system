<?php

namespace App\Http\Middleware;

use App\Models\Facility;
use App\Models\StaffAssignment;
use App\Models\SupportAccessGrant;
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
            $this->context->setFacility($this->resolveFacility($request, $user, $tenant));
        }

        return $next($request);
    }

    /**
     * Facility: a session selection re-validated against the user's staff
     * assignments (any facility for tenant-wide admins), else the user's
     * primary assignment, else the tenant's main facility.
     */
    protected function resolveFacility(Request $request, $user, Tenant $tenant): ?Facility
    {
        $facilities = Facility::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('active', true)
            ->get();

        if ($facilities->isEmpty()) {
            return null;
        }

        $assigned = StaffAssignment::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->pluck('facility_id');

        $selectedId = (int) $request->session()->get('current_facility_id');

        if ($selectedId && $facilities->contains('id', $selectedId)
            && ($assigned->contains($selectedId) || $assigned->isEmpty() || $facilities->count() === 1)) {
            return $facilities->firstWhere('id', $selectedId);
        }

        $facility = $facilities->firstWhere('id', $assigned->first())
            ?? $facilities->firstWhere('is_main', true)
            ?? $facilities->first();

        $request->session()->put('current_facility_id', $facility->id);

        return $facility;
    }

    protected function resolveTenant(Request $request, $user): ?Tenant
    {
        $selectedId = $request->session()->get('current_tenant_id');

        // Break-glass: a platform admin with an active, audited support grant
        // gets read-only access to that one tenant until the grant expires.
        if ($user->isPlatformAdmin() && ($supportTenant = $request->session()->get('support_tenant_id'))) {
            if (SupportAccessGrant::activeFor($user->id, (int) $supportTenant)) {
                $request->attributes->set('support_access', true);

                return Tenant::find($supportTenant);
            }

            $request->session()->forget('support_tenant_id');
        }

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
