<?php

namespace App\Http\Controllers;

use App\Models\HieCredential;
use App\Models\Tenant;
use App\Services\Billing\InsuranceService;
use App\Services\Billing\RemittanceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inbound status callbacks and remittances from SHA / the HIE.
 *
 * POST /hooks/sha/{tenant:slug}
 * Header: X-HMS-Signature: sha256=<hex HMAC-SHA256 of the raw body using the tenant's webhook secret>
 *
 * Bodies:
 *  {"event":"claim.status","claims":[{"reference":"…","status":"APPROVED|REJECTED|PAID","approved_amount":1200,"reason":"…"}]}
 *  {"event":"remittance","reference":"PV-2026-11-001","paid_on":"2026-11-05","lines":[{"claim_reference":"…","amount":1200}]}
 *
 * The URL and secret are shown under SHA & HIE integration → Connection.
 */
class ShaCallbackController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, TenantContext $context, RemittanceService $remittances, InsuranceService $insurance): JsonResponse
    {
        $credential = HieCredential::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->first();
        $secret = $credential?->webhook_secret;

        abort_unless($secret && $tenant->isActive(), 404);

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-HMS-Signature')), 401, 'Invalid signature');

        $context->set($tenant);
        $payload = $request->json()->all();

        try {
            return match ($payload['event'] ?? null) {
                'remittance' => $this->remittance($payload, $remittances),
                'claim.status' => $this->statuses($payload, $remittances, $insurance),
                default => response()->json(['error' => 'Unknown event'], 422),
            };
        } catch (ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        }
    }

    protected function remittance(array $p, RemittanceService $remittances): JsonResponse
    {
        $r = $remittances->ingest('SHA', (string) ($p['reference'] ?? ''), $p['paid_on'] ?? null, $p['lines'] ?? [], 'callback');

        return response()->json(['remittance' => $r->reference, 'matched' => $r->matched_count, 'unmatched' => $r->unmatched_count]);
    }

    protected function statuses(array $p, RemittanceService $remittances, InsuranceService $insurance): JsonResponse
    {
        $results = [];

        foreach ($p['claims'] ?? [] as $row) {
            $claim = $remittances->findClaim((string) ($row['reference'] ?? ''));
            $state = strtoupper((string) ($row['status'] ?? ''));
            $local = match ($state) {
                'APPROVED' => 'approved', 'PARTIALLY_APPROVED' => 'partially_approved', 'REJECTED', 'DECLINED' => 'rejected', 'PAID' => 'paid', default => null,
            };

            if (! $claim || ! $local) {
                $results[] = ['reference' => $row['reference'] ?? null, 'result' => $claim ? 'ignored' : 'not_found'];

                continue;
            }

            $claim->update(['external_status' => $state, 'last_synced_at' => now()]);

            if (in_array($claim->status, ['submitted', 'approved', 'partially_approved'], true) && $claim->status !== $local) {
                $insurance->recordResponse($claim, $local, isset($row['approved_amount']) ? (float) $row['approved_amount'] : null, $row['reason'] ?? null);
            }

            $results[] = ['reference' => $row['reference'], 'result' => $claim->fresh()->status];
        }

        return response()->json(['claims' => $results]);
    }
}
