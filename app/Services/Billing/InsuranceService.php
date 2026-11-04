<?php

namespace App\Services\Billing;

use App\Contracts\ClaimGateway;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Insurance claim lifecycle. Submission goes through a ClaimGateway so a
 * real SHA / insurer integration can be plugged in; the default gateway
 * records the claim locally for manual submission.
 */
class InsuranceService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected BillingService $billing,
        protected AuditLogger $audit,
        protected ClaimGatewayResolver $gateways,
    ) {}

    public function gatewayFor(InsuranceClaim $claim): ClaimGateway
    {
        return $this->gateways->for($claim);
    }

    public function createClaim(Invoice $invoice, ?string $preauth = null): InsuranceClaim
    {
        if (! $invoice->patient_insurance_id) {
            throw ValidationException::withMessages(['claim' => __('This invoice is not linked to an insurance cover.')]);
        }

        if ($invoice->claims()->whereNotIn('status', ['rejected'])->exists()) {
            throw ValidationException::withMessages(['claim' => __('A claim already exists for this invoice.')]);
        }

        $claim = InsuranceClaim::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'patient_insurance_id' => $invoice->patient_insurance_id,
            'claim_number' => $this->numbers->next('CLM', $invoice->tenant_id),
            'preauthorization_number' => $preauth,
            'amount_claimed' => $invoice->balance,
            'status' => 'draft',
        ]);

        $this->audit->log('insurance.claim_created', $claim);

        return $claim;
    }

    public function submit(InsuranceClaim $claim, array $context = []): InsuranceClaim
    {
        if ($claim->status !== 'draft') {
            throw ValidationException::withMessages(['claim' => __('Only draft claims can be submitted.')]);
        }

        $gateway = $this->gateways->for($claim);

        // Lock against a concurrent double-submit of the same claim.
        $lock = cache()->lock('claim-submit:'.$claim->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['claim' => __('This claim is already being submitted.')]);
        }

        try {
            // Amount is refreshed at submission so late charges are included.
            $claim->update(['gateway' => $gateway->key(), 'amount_claimed' => $claim->invoice->fresh()->balance]);
            $reference = $gateway->submit($claim, $context);
        } finally {
            $lock->release();
        }

        $claim->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => Auth::id(),
            'preauthorization_number' => $claim->preauthorization_number ?: $reference,
        ]);

        $this->audit->log('insurance.claim_submitted', $claim, ['gateway' => $gateway->key(), 'reference' => $claim->external_reference]);

        return $claim;
    }

    /**
     * Pull the payer's status for an electronically submitted claim and
     * apply it (approval, rejection, payment) through recordResponse.
     */
    public function syncStatus(InsuranceClaim $claim): ?array
    {
        $result = $this->gateways->for($claim)->status($claim);

        if ($result === null) {
            return null;
        }

        $claim->update(['external_status' => $result['external_status'] ?? null, 'last_synced_at' => now()]);

        $local = $result['status'] ?? null;

        if ($claim->status === 'draft' && $local === 'submitted') {
            $claim->update(['status' => 'submitted', 'submitted_at' => $claim->submitted_at ?? now()]);
        } elseif ($local && $local !== 'submitted' && $local !== $claim->status && in_array($claim->status, ['submitted', 'approved', 'partially_approved'], true)) {
            $this->recordResponse($claim, $local, $result['approved'] ?? ($local === 'rejected' ? null : (float) $claim->amount_claimed), $result['reason'] ?? null);
        }

        return $result;
    }

    public function recordResponse(InsuranceClaim $claim, string $status, ?float $approved, ?string $reason): InsuranceClaim
    {
        if (! in_array($claim->status, ['submitted', 'approved', 'partially_approved'], true)) {
            throw ValidationException::withMessages(['claim' => __('Only submitted claims can receive a response.')]);
        }

        return DB::transaction(function () use ($claim, $status, $approved, $reason) {
            $claim->update([
                'status' => $status,
                'amount_approved' => $approved,
                'rejection_reason' => $reason,
                'responded_at' => now(),
            ]);

            if ($status === 'paid' && $approved > 0) {
                $amount = min($approved, (float) $claim->invoice->fresh()->balance);
                if ($amount > 0) {
                    $this->billing->receivePayment($claim->invoice, $amount, 'insurance', $claim->claim_number);
                }
            }

            $this->audit->log('insurance.claim_'.$status, $claim, ['approved' => $approved]);

            return $claim;
        });
    }
}
