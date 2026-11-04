<?php

namespace App\Services\Clinical;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Referral;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KEPH referral system. Outgoing: draft → sent → accepted/rejected →
 * completed (outcome or back-referral recorded). Incoming: expected (sent)
 * → arrived (patient checked in, visit opened) → completed (back-referral
 * written to the referring facility).
 */
class ReferralService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected EncounterService $encounters,
        protected AuditLogger $audit,
        protected TenantContext $context,
    ) {}

    /**
     * Draft an outgoing referral from a visit, pre-filled with the visit's
     * diagnoses so the receiving clinician gets a usable summary.
     */
    public function createOutgoing(Encounter $encounter, array $data): Referral
    {
        if (in_array($encounter->stage, EncounterStage::terminal(), true)) {
            throw ValidationException::withMessages(['reason' => __('This visit is closed. Open a new visit to refer the patient.')]);
        }

        $referral = Referral::create($this->clean($data) + [
            'tenant_id' => $encounter->tenant_id,
            'facility_id' => $this->context->facilityId() ?? $encounter->facility_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'referral_number' => $this->numbers->next('REF', $encounter->tenant_id),
            'direction' => 'outgoing',
            'kind' => $data['kind'] ?? 'referral',
            'status' => 'draft',
            'referring_clinician' => $data['referring_clinician'] ?? Auth::user()?->name,
            'created_by' => Auth::id(),
        ]);

        $this->audit->log('referral.created', $referral, ['to' => $referral->other_facility_name, 'urgency' => $referral->urgency]);

        return $referral;
    }

    /**
     * Send the referral. An outpatient visit moves to billing (the patient
     * is leaving); an admitted patient is discharged with type "referred".
     */
    public function send(Referral $referral): Referral
    {
        $this->requireStatus($referral, ['draft'], __('Only a draft referral can be sent.'));

        return DB::transaction(function () use ($referral) {
            $referral->update(['status' => 'sent', 'sent_at' => now(), 'updated_by' => Auth::id()]);

            $encounter = $referral->encounter;
            if ($encounter && in_array($encounter->stage, [EncounterStage::WAITING_TRIAGE, EncounterStage::WAITING_DOCTOR, EncounterStage::IN_CONSULTATION, EncounterStage::AWAITING_RESULTS], true)) {
                $this->encounters->transition($encounter, EncounterStage::WAITING_BILLING, __('Referred to :facility', ['facility' => $referral->other_facility_name]));
            }

            $this->audit->log('referral.sent', $referral);

            return $referral;
        });
    }

    /**
     * Record the receiving facility's response to an outgoing referral.
     */
    public function recordResponse(Referral $referral, string $status, ?string $feedback): Referral
    {
        if (! $referral->isOutgoing()) {
            throw ValidationException::withMessages(['status' => __('Responses are recorded on outgoing referrals.')]);
        }

        $allowed = ['sent' => ['accepted', 'rejected', 'completed'], 'accepted' => ['completed'], 'arrived' => ['completed']];
        if (! in_array($status, $allowed[$referral->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => __('Cannot move a :from referral to :to.', ['from' => $referral->status, 'to' => $status])]);
        }

        if ($status === 'rejected' && ! trim((string) $feedback)) {
            throw ValidationException::withMessages(['feedback' => __('Give the reason the referral was declined.')]);
        }

        $referral->update([
            'status' => $status,
            'feedback' => $feedback ?: $referral->feedback,
            'completed_at' => $status === 'completed' ? now() : $referral->completed_at,
            'updated_by' => Auth::id(),
        ]);
        $this->audit->log('referral.'.$status, $referral);

        return $referral;
    }

    /**
     * Register a patient referred in from another facility (expected arrival).
     */
    public function createIncoming(Patient $patient, array $data): Referral
    {
        $referral = Referral::create($this->clean($data) + [
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $this->context->facilityId() ?? $patient->facility_id,
            'patient_id' => $patient->id,
            'referral_number' => $this->numbers->next('REF', $patient->tenant_id),
            'direction' => 'incoming',
            'kind' => $data['kind'] ?? 'referral',
            'status' => 'sent',
            'sent_at' => now(),
            'created_by' => Auth::id(),
        ]);

        $this->audit->log('referral.incoming_registered', $referral, ['from' => $referral->other_facility_name]);

        return $referral;
    }

    /**
     * The referred patient has arrived: open (or link) their visit, with
     * priority from the referral's urgency.
     */
    public function receive(Referral $referral, array $visit = []): Referral
    {
        if ($referral->isOutgoing()) {
            throw ValidationException::withMessages(['status' => __('Only incoming referrals are received here.')]);
        }
        $this->requireStatus($referral, ['sent', 'accepted'], __('This referral has already been received or closed.'));

        return DB::transaction(function () use ($referral, $visit) {
            $patient = $referral->patient;
            $encounter = $patient->activeEncounter() ?? $this->encounters->checkIn($patient, [
                'type' => $referral->urgency === 'emergency' ? 'emergency' : 'outpatient',
                'priority' => match ($referral->urgency) {
                    'emergency' => 'emergency', 'urgent' => 'urgent', default => 'normal',
                },
                'payer_type' => $visit['payer_type'] ?? 'cash',
                'department_id' => $visit['department_id'] ?? null,
                'attending_doctor_id' => $visit['attending_doctor_id'] ?? null,
                'chief_complaint' => __('Referred from :facility: :reason', ['facility' => $referral->other_facility_name, 'reason' => mb_strimwidth($referral->reason, 0, 150, '…')]),
            ]);

            $referral->update(['status' => 'arrived', 'arrived_at' => now(), 'received_encounter_id' => $encounter->id, 'updated_by' => Auth::id()]);
            $this->audit->log('referral.arrived', $referral, ['encounter' => $encounter->encounter_number]);

            return $referral;
        });
    }

    /**
     * Close an incoming referral with a back-referral (feedback to the
     * referring facility: findings, treatment, follow-up plan).
     */
    public function backRefer(Referral $referral, string $feedback): Referral
    {
        if ($referral->isOutgoing()) {
            throw ValidationException::withMessages(['feedback' => __('Back-referrals are written on incoming referrals.')]);
        }
        $this->requireStatus($referral, ['arrived'], __('The patient must have arrived before a back-referral is written.'));

        if (mb_strlen(trim($feedback)) < 10) {
            throw ValidationException::withMessages(['feedback' => __('Write the findings, treatment and follow-up plan for the referring facility.')]);
        }

        $referral->update(['status' => 'completed', 'feedback' => $feedback, 'completed_at' => now(), 'kind' => 'back_referral', 'updated_by' => Auth::id()]);
        $this->audit->log('referral.back_referred', $referral);

        return $referral;
    }

    public function cancel(Referral $referral, string $reason): Referral
    {
        $this->requireStatus($referral, ['draft', 'sent'], __('Only draft or pending referrals can be cancelled.'));

        $referral->update(['status' => 'cancelled', 'status_reason' => $reason, 'updated_by' => Auth::id()]);
        $this->audit->log('referral.cancelled', $referral, ['reason' => $reason]);

        return $referral;
    }

    protected function requireStatus(Referral $referral, array $statuses, string $message): void
    {
        if (! in_array($referral->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    protected function clean(array $data): array
    {
        return collect($data)->only([
            'other_facility_name', 'other_facility_code', 'other_facility_level', 'department', 'urgency',
            'reason', 'clinical_summary', 'investigations', 'treatment_given', 'transport', 'referring_clinician',
        ])->reject(fn ($v) => $v === null || $v === '')->all();
    }
}
