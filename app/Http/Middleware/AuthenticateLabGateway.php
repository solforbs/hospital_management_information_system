<?php

namespace App\Http\Middleware;

use App\Models\LabGateway;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a device gateway by its bearer token and scopes the request
 * to that gateway's hospital (and facility), so the tenant global scope
 * applies to every query in the LIS API.
 */
class AuthenticateLabGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $gateway = LabGateway::findByToken($request->bearerToken());

        if (! $gateway || ! $gateway->tenant || $gateway->tenant->status === 'suspended') {
            return response()->json(['message' => 'Invalid or inactive gateway token.'], 401);
        }

        $context = app(TenantContext::class);
        $context->set($gateway->tenant);
        if ($gateway->facility) {
            $context->setFacility($gateway->facility);
        }

        // Cheap presence tracking: at most one write per minute.
        if (! $gateway->last_seen_at || $gateway->last_seen_at->lt(now()->subMinute()) || $gateway->last_ip !== $request->ip()) {
            $gateway->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(), 'version' => $request->header('X-Gateway-Version', $gateway->version)])->save();
        }

        $request->attributes->set('lab_gateway', $gateway);

        return $next($request);
    }
}
