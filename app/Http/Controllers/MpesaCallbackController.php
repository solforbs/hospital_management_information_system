<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Services\Billing\MpesaService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Safaricom STK push callback: POST /hooks/mpesa/{tenant}/{token}.
 * The unguessable per-tenant token in the URL authenticates the caller
 * (Safaricom does not sign callbacks); the CheckoutRequestID must match a
 * request this tenant initiated.
 */
class MpesaCallbackController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, string $token, TenantContext $context, MpesaService $mpesa): JsonResponse
    {
        $credential = IntegrationCredential::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('provider', 'mpesa')->first();
        abort_unless($credential && hash_equals((string) $credential->get('callback_token'), $token), 404);

        $context->set($tenant);
        $result = $mpesa->handleCallback($request->json()->all());

        abort_if($result && $result->tenant_id !== $tenant->id, 404);

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
