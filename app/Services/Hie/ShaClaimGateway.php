<?php

namespace App\Services\Hie;

use App\Contracts\ClaimGateway;
use App\Models\BedAssignment;
use App\Models\InsuranceClaim;
use App\Models\InvoiceItem;
use App\Models\LabOrderItem;
use App\Models\RadiologyOrder;
use App\Models\ServiceItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * SHA eClaims through the DHA HIE, keyed on the visit consent token:
 * interventions → diagnoses → doctor → billing lines → preview → submit.
 *
 * Each step's result is checkpointed on the claim (gateway_response.progress)
 * so a failure part-way resumes without posting duplicate lines. Submission
 * itself is never retried automatically: an unconfirmed submit is marked
 * SUBMIT_UNCONFIRMED and must be reconciled with status().
 *
 * Endpoint paths follow hie-docs eClaims as mirrored by the public SDK;
 * confirm field names in UAT before production use.
 */
class ShaClaimGateway implements ClaimGateway
{
    public const DISCHARGE_REASONS = ['RECOVERED' => 'Recovered', 'REFERRED' => 'Referred', 'DECEASED' => 'Deceased', 'ABSCONDED' => 'Absconded', 'OTHER' => 'Other'];

    public function __construct(protected HieClient $client, protected ShaService $sha) {}

    public function key(): string
    {
        return 'sha';
    }

    public function submit(InsuranceClaim $claim, array $context = []): ?string
    {
        $invoice = $claim->invoice;
        $encounter = $invoice->encounter;

        if (! $encounter) {
            throw ValidationException::withMessages(['claim' => __('SHA claims must belong to a visit.')]);
        }

        if ($claim->external_status === 'SUBMIT_UNCONFIRMED' || Arr::get($claim->gateway_response ?? [], 'submitted')) {
            throw ValidationException::withMessages(['claim' => __('A previous submission was not confirmed by SHA. Use "Check SHA status" before trying again.')]);
        }

        $this->sha->requireConsent($encounter);
        $token = $encounter->sha_consent_token;

        $otp = trim((string) ($context['otp'] ?? ''));
        $reason = $context['discharge_reason'] ?? 'RECOVERED';

        if ($otp === '') {
            throw ValidationException::withMessages(['otp' => __('Enter the discharge OTP the member received.')]);
        }

        if (! array_key_exists($reason, self::DISCHARGE_REASONS)) {
            throw ValidationException::withMessages(['discharge_reason' => __('Choose a discharge reason.')]);
        }

        $diagnoses = $encounter->diagnoses()->whereNotNull('code')->where('code', '!=', '')->get();
        if ($diagnoses->isEmpty()) {
            throw ValidationException::withMessages(['claim' => __('SHA requires at least one ICD-coded diagnosis on the visit.')]);
        }

        $doctor = $encounter->doctor;
        if (! $doctor?->regulator || ! $doctor?->registration_number) {
            throw ValidationException::withMessages(['claim' => __('The attending doctor needs a regulator and registration number (Integrations → Practitioners).')]);
        }

        $items = $invoice->items()->with('source')->get();
        if ($items->isEmpty() || (float) $invoice->total <= 0) {
            throw ValidationException::withMessages(['claim' => __('The invoice has no billable lines.')]);
        }

        $progress = Arr::get($claim->gateway_response ?? [], 'progress', []);
        $save = function () use ($claim, &$progress) {
            $claim->update(['gateway' => 'sha', 'gateway_response' => array_merge($claim->gateway_response ?? [], ['progress' => $progress])]);
        };

        // 1. Interventions: every code the lines will be billed under must be active on the visit.
        $consented = $encounter->sha_interventions ?? [];
        $lineCodes = $items->mapWithKeys(fn (InvoiceItem $i) => [$i->id => $this->interventionFor($i, $consented)]);

        foreach ($lineCodes->unique()->diff($consented)->diff($progress['interventions'] ?? []) as $code) {
            $this->client->post('/claims/interventions', ['consent_token' => $token, 'intervention_code' => $code]);
            $progress['interventions'][] = $code;
            $save();
        }

        // 2. Diagnoses per intervention.
        $icd = $diagnoses->pluck('code')->map(fn ($c) => strtoupper(trim($c)))->unique()->values();
        foreach ($lineCodes->unique() as $code) {
            foreach ($icd as $c) {
                $key = $code.'|'.$c;
                if (in_array($key, $progress['diagnoses'] ?? [], true)) {
                    continue;
                }
                $this->client->post('/claims/diagnoses', ['consent_token' => $token, 'icd_code' => $c, 'intervention_code' => $code]);
                $progress['diagnoses'][] = $key;
                $save();
            }
        }

        // 3. Attending doctor (validated by SHA against the Health Worker Registry).
        $practitioner = [
            'practitioner_identification_type' => 'registration_number',
            'practitioner_identification_number' => $doctor->registration_number,
            'practitioner_regulation_body' => $doctor->regulator,
        ];

        if (empty($progress['doctor'])) {
            $this->client->post('/claims/doctors', ['consent_token' => $token] + $practitioner);
            $progress['doctor'] = true;
            $save();
        }

        // 4. Billing lines.
        foreach ($items as $item) {
            if (isset($progress['lines'][$item->id])) {
                continue;
            }

            $body = $this->client->postMultipart('/claims/lines', [
                'consent_token' => $token,
                'intervention_code' => $lineCodes[$item->id],
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'quantity' => (string) (float) $item->quantity,
                'scheme_code' => Arr::get($encounter->sha_eligibility ?? [], 'scheme_code') ?: config('hms.hie.scheme_code'),
                'charge_date' => $item->created_at->toDateString(),
                'diagnoses' => $icd->all(),
                'service_name' => $item->description,
                'service_identifier' => $this->serviceIdentifier($item),
                'attachments' => [],
            ] + $practitioner);

            $progress['lines'][$item->id] = $body['guid'] ?? $body['line_guid'] ?? true;
            $save();
        }

        // 4b. Supporting documents (discharge summary, reports...) not yet sent.
        foreach ($claim->documents()->whereNull('sent_at')->get() as $doc) {
            $disk = Storage::disk('local');
            if (! $disk->exists($doc->path)) {
                continue;
            }

            $body = $this->client->postMultipart('/claims/attachments', [
                'consent_token' => $token,
                'document_type' => $doc->document_type,
                'intervention_code' => $lineCodes->first(),
            ], ['file_blob' => [$disk->get($doc->path), $doc->original_name]]);

            $doc->update(['sent_at' => now(), 'external_reference' => $body['guid'] ?? null]);
        }

        // 5. Preview: SHA reports blockers (missing documents, outstanding preauth...).
        $preview = $this->client->post('/claims/preview', ['consent_token' => $token]);
        $blockers = collect($preview['blockers'] ?? Arr::get($preview, 'data.blockers', []))->map(fn ($b) => is_array($b) ? ($b['message'] ?? $b['code'] ?? json_encode($b)) : $b)->filter();

        if ($blockers->isNotEmpty()) {
            $claim->update(['external_status' => 'BLOCKED', 'gateway_response' => array_merge($claim->gateway_response ?? [], ['preview' => $preview])]);
            throw ValidationException::withMessages(['claim' => __('SHA will not accept this claim yet: :list', ['list' => $blockers->implode('; ')])]);
        }

        $shaInvoice = $preview['invoice_number'] ?? Arr::get($preview, 'data.invoice_number');

        // 6. Submit — exactly once.
        try {
            $result = $this->client->post('/claims/submit', [
                'consent_token' => $token,
                'invoice_number' => $shaInvoice,
                'discharge_reason' => $reason,
                'otp' => $otp,
            ]);
        } catch (HieException $e) {
            if ($e->outcomeUnknown) {
                $claim->update(['external_reference' => $shaInvoice, 'external_status' => 'SUBMIT_UNCONFIRMED']);
            } elseif ($e->status && $e->status < 500) {
                throw ValidationException::withMessages(['otp' => __('SHA rejected the submission: :msg', ['msg' => $e->body['message'] ?? $e->getMessage()])]);
            }
            throw $e;
        }

        $claim->update([
            'external_reference' => $result['invoice_number'] ?? $shaInvoice,
            'external_status' => $result['workflow_state'] ?? 'SUBMITTED',
            'gateway_response' => array_merge($claim->gateway_response ?? [], [
                'guid' => $result['guid'] ?? null,
                'provider_claim_no' => $result['provider_claim_no'] ?? null,
                'submitted' => true,
            ]),
            'last_synced_at' => now(),
        ]);

        return $result['provider_claim_no'] ?? $result['invoice_number'] ?? $shaInvoice;
    }

    public function status(InsuranceClaim $claim): ?array
    {
        $guid = Arr::get($claim->gateway_response ?? [], 'guid');
        $pcn = Arr::get($claim->gateway_response ?? [], 'provider_claim_no');

        if (! $guid && ! $pcn) {
            // Unconfirmed submit: ask the visit-side preview what state the claim is in.
            $encounter = $claim->invoice->encounter;
            $body = $this->client->post('/claims/preview', ['consent_token' => $encounter->sha_consent_token]);
            $state = strtoupper((string) ($body['workflow_state'] ?? 'UNKNOWN'));

            return ['status' => in_array($state, ['SUBMITTED', 'IN_REVIEW'], true) ? 'submitted' : null, 'external_status' => $state, 'approved' => null, 'reason' => null];
        }

        $body = $this->client->get('/claims/preview/payer', array_filter(['guid' => $guid, 'provider_claim_no' => $pcn]));
        $state = strtoupper((string) ($body['status'] ?? $body['workflow_state'] ?? Arr::get($body, 'data.status', 'UNKNOWN')));
        $approved = $body['approved_amount'] ?? Arr::get($body, 'data.approved_amount');

        $local = match (true) {
            in_array($state, ['PAID', 'SETTLED', 'REMITTED'], true) => 'paid',
            in_array($state, ['APPROVED', 'APPROVED_FOR_PAYMENT'], true) => 'approved',
            in_array($state, ['PARTIALLY_APPROVED'], true) => 'partially_approved',
            in_array($state, ['REJECTED', 'DECLINED'], true) => 'rejected',
            default => null,
        };

        return [
            'status' => $local,
            'external_status' => $state,
            'approved' => $approved !== null ? (float) $approved : null,
            'reason' => $body['rejection_reason'] ?? $body['reason'] ?? null,
        ];
    }

    /**
     * SHA intervention a line is billed under: the catalog mapping when it is
     * set, otherwise the visit's first consented intervention.
     */
    protected function interventionFor(InvoiceItem $item, array $consented): string
    {
        $source = $item->source;

        $mapped = match (true) {
            $source instanceof LabOrderItem => $source->test?->sha_intervention_code,
            $source instanceof RadiologyOrder => $source->exam?->sha_intervention_code,
            // ICU/HDU/NICU per-diem benefits (SHA-03-*) are mapped on the ward.
            $source instanceof BedAssignment => $source->bed?->ward?->sha_intervention_code,
            default => ServiceItem::where('name', $item->description)->where('category', $item->category)->value('sha_intervention_code'),
        };

        return $mapped ?: ($consented[0] ?? config('hms.hie.default_intervention'));
    }

    protected function serviceIdentifier(InvoiceItem $item): string
    {
        $source = $item->source;

        return match (true) {
            $source instanceof LabOrderItem => (string) $source->test?->code,
            $source instanceof RadiologyOrder => (string) $source->exam?->code,
            $source instanceof BedAssignment => 'BED-'.($source->bed?->ward?->code ?? 'WARD'),
            default => (string) (ServiceItem::where('name', $item->description)->value('code') ?? 'INV-ITEM-'.$item->id),
        };
    }
}
