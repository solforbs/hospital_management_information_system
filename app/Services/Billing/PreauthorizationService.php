<?php

namespace App\Services\Billing;

use App\Models\Encounter;
use App\Models\Preauthorization;
use App\Services\Audit\AuditLogger;
use App\Services\Hie\HieClient;
use App\Services\Hie\HieException;
use App\Services\Hie\ShaService;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Pre-authorisation for planned admissions and procedures. SHA visits go
 * electronically through the HIE (/preauths, keyed on the visit consent
 * token); other payers are tracked manually with the payer's response.
 */
class PreauthorizationService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected AuditLogger $audit,
        protected HieClient $hie,
        protected ShaService $sha,
    ) {}

    public function isSha(Encounter $encounter): bool
    {
        $insurance = $encounter->patient->insurances()->where('active', true)->with('provider')->latest('id')->first();

        return $encounter->payer_type === 'insurance' && $insurance?->provider?->type === 'sha' && $this->hie->isEnabled();
    }

    public function create(Encounter $encounter, array $data, ?Model $source = null): Preauthorization
    {
        if (! $encounter->isOpen()) {
            throw ValidationException::withMessages(['preauth' => __('Pre-authorisation needs an open visit.')]);
        }

        $codes = $encounter->diagnoses()->whereNotNull('code')->pluck('code')->unique()->values()->all();

        $preauth = Preauthorization::create([
            'tenant_id' => $encounter->tenant_id,
            'encounter_id' => $encounter->id,
            'patient_id' => $encounter->patient_id,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'preauth_number' => $this->numbers->next('PA', $encounter->tenant_id),
            'gateway' => $this->isSha($encounter) ? 'sha' : 'manual',
            'intervention_code' => $data['intervention_code'] ?? null,
            'diagnosis_codes' => $codes ?: null,
            'justification' => $data['justification'],
            'estimated_cost' => (float) ($data['estimated_cost'] ?? 0),
            'status' => 'draft',
            'requested_by' => Auth::id(),
        ]);

        $this->audit->log('preauth.created', $preauth, ['gateway' => $preauth->gateway]);

        return $preauth;
    }

    public function submit(Preauthorization $preauth): Preauthorization
    {
        if ($preauth->status !== 'draft') {
            throw ValidationException::withMessages(['preauth' => __('Only a draft pre-authorisation can be submitted.')]);
        }

        if ($preauth->gateway === 'sha') {
            $encounter = $preauth->encounter;
            $this->sha->requireConsent($encounter);

            if (! $preauth->intervention_code) {
                throw ValidationException::withMessages(['preauth' => __('SHA pre-authorisation needs the intervention code.')]);
            }
            if (! $preauth->diagnosis_codes) {
                throw ValidationException::withMessages(['preauth' => __('Record an ICD-11 diagnosis on the visit first.')]);
            }

            $body = $this->hie->postMultipart('/preauths', [
                'consent_token' => $encounter->sha_consent_token,
                'intervention_code' => $preauth->intervention_code,
                'diagnoses' => $preauth->diagnosis_codes,
                'justification' => $preauth->justification,
                'estimated_cost' => number_format((float) $preauth->estimated_cost, 2, '.', ''),
            ]);

            $preauth->update([
                'external_reference' => $body['preauth_id'] ?? Arr::get($body, 'data.preauth_id') ?? $body['id'] ?? null,
                'external_status' => strtoupper((string) ($body['status'] ?? 'SUBMITTED')),
                'gateway_response' => $body,
            ]);
        }

        $preauth->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->audit->log('preauth.submitted', $preauth);

        return $preauth;
    }

    /** Pull SHA's decision for an electronic pre-authorisation. */
    public function refresh(Preauthorization $preauth): Preauthorization
    {
        if ($preauth->gateway !== 'sha' || ! $preauth->external_reference) {
            return $preauth;
        }

        $body = $this->hie->get('/preauths', ['consent_token' => $preauth->encounter->sha_consent_token, 'preauth_id' => $preauth->external_reference]);
        $record = collect($body['data'] ?? [$body])->firstWhere('preauth_id', $preauth->external_reference) ?? ($body['data'][0] ?? $body);
        $state = strtoupper((string) ($record['status'] ?? 'PENDING'));

        $local = match (true) {
            in_array($state, ['ACTIVE', 'APPROVED', 'AUTHORIZED_PENDING_VISIT', 'FINALISED'], true) => 'approved',
            in_array($state, ['REJECTED', 'DECLINED'], true) => 'rejected',
            in_array($state, ['CANCELLED'], true) => 'cancelled',
            default => 'submitted',
        };

        $preauth->update([
            'external_status' => $state,
            'status' => $local,
            'approved_amount' => $local === 'approved' ? ($record['approved_amount'] ?? $preauth->estimated_cost) : $preauth->approved_amount,
            'responded_at' => $local !== 'submitted' ? now() : $preauth->responded_at,
        ]);

        return $preauth;
    }

    /** Record a payer's decision received outside the system (manual payers). */
    public function recordResponse(Preauthorization $preauth, string $status, ?float $amount, ?string $note): Preauthorization
    {
        if (! in_array($preauth->status, ['submitted'], true)) {
            throw ValidationException::withMessages(['preauth' => __('Only a submitted pre-authorisation can receive a response.')]);
        }

        if (! in_array($status, ['approved', 'partially_approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['preauth' => __('Choose a valid outcome.')]);
        }

        $preauth->update([
            'status' => $status,
            'approved_amount' => $status === 'rejected' ? null : ($amount ?? $preauth->estimated_cost),
            'response_note' => $note,
            'responded_at' => now(),
        ]);
        $this->audit->log('preauth.'.$status, $preauth);

        return $preauth;
    }

    public function cancel(Preauthorization $preauth, string $reason): Preauthorization
    {
        if (in_array($preauth->status, ['rejected', 'cancelled'], true)) {
            return $preauth;
        }

        if ($preauth->gateway === 'sha' && $preauth->external_reference) {
            try {
                $this->hie->post('/preauths/cancel', ['consent_token' => $preauth->encounter->sha_consent_token, 'preauth_id' => $preauth->external_reference]);
            } catch (HieException) {
                // Cancel locally; SHA expires unused pre-authorisations.
            }
        }

        $preauth->update(['status' => 'cancelled', 'response_note' => $reason]);
        $this->audit->log('preauth.cancelled', $preauth, ['reason' => $reason]);

        return $preauth;
    }
}
