<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-institution security policy (Institution profile → Security):
 *  - allowed IP ranges: staff can only use the system from listed networks;
 *  - idle timeout: sessions end after N minutes without activity.
 * Break-glass support sessions are read-only and time-boxed separately.
 */
class EnforceSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = Auth::user();

        if (! $tenant || ! $user) {
            return $next($request);
        }

        $policy = data_get($tenant->settings, 'security', []);

        $ranges = array_values(array_filter(array_map('trim', (array) ($policy['allowed_ips'] ?? []))));
        if ($ranges && ! $user->isPlatformAdmin() && ! IpUtils::checkIp((string) $request->ip(), $ranges)) {
            abort(403, __('Access to :name is restricted to approved networks. Your address :ip is not allowed.', ['name' => $tenant->displayName(), 'ip' => $request->ip()]));
        }

        $idle = (int) ($policy['idle_minutes'] ?? 0);
        if ($idle > 0 && $request->hasSession()) {
            $last = (int) $request->session()->get('last_activity_at', 0);

            if ($last && (time() - $last) > $idle * 60) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', __('You were signed out after :m minutes of inactivity.', ['m' => $idle]));
            }

            $request->session()->put('last_activity_at', time());
        }

        return $next($request);
    }
}
