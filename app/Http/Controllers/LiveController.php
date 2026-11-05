<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Channel versions for the signed-in user's tenant (polled by resources/js/app.js). */
class LiveController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->id();
        abort_unless($tenant, 404);

        $channels = array_slice(explode(',', (string) $request->query('c', '')), 0, 20);

        return response()->json(Live::versions($tenant, $channels))->header('Cache-Control', 'no-store');
    }
}
