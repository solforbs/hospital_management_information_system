<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Support\FacilityLevel;
use App\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes of modules that are not offered at the active facility's
 * KEPH level (or were switched off for it), so a dispensary cannot reach
 * theatre or ICU pages by URL even though the sidebar already hides them.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $facility = app(TenantContext::class)->facility();

        if ($routeName && $facility) {
            $module = ModuleRegistry::moduleForRoute($routeName);

            if ($module && ! ModuleRegistry::enabledAt($facility, $module['key'])) {
                abort(403, __(':module is not offered at :facility (:level). An administrator can enable it under Facilities.', [
                    'module' => $module['label'],
                    'facility' => $facility->name,
                    'level' => FacilityLevel::label($facility->level),
                ]));
            }
        }

        return $next($request);
    }
}
