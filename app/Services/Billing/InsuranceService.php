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
        protected ClaimGateway $gateway,
    ) {}

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

    public function submit(InsuranceClaim $claim): InsuranceClaim
    {
        if ($claim->status !== 'draft') {
            throw ValidationException::withMessages(['claim' => __('Only draft claims can be submitted.')]);
        }

        $reference = $this->gateway->submit($claim);

        $claim->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => Auth::id(),
            'preauthorization_number' => $claim->preauthorization_number ?: $reference,
        ]);

        $this->audit->log('insurance.claim_submitted', $claim);

        return $claim;
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
