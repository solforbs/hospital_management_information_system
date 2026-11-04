<?php

namespace App\Services\Clinical;

use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\PatientAllergy;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Doctor consultation: starting a consultation (claims the patient and
 * bills the consultation fee once), notes with sign/amend semantics
 * (signed notes are immutable; amendments create a linked new version),
 * diagnoses and allergies.
 */
class ConsultationService
{
    public function __construct(
        protected EncounterService $encounters,
        protected BillingService $billing,
        protected AuditLogger $audit,
    ) {}

    public function start(Encounter $encounter): Encounter
    {
        return DB::transaction(function () use ($encounter) {
            $encounter = Encounter::lockForUpdate()->findOrFail($encounter->id);

            if (! $encounter->attending_doctor_id) {
                $encounter->update(['attending_doctor_id' => Auth::id()]);
            }

            if (in_array($encounter->stage, [EncounterStage::WAITING_TRIAGE, EncounterStage::WAITING_DOCTOR, EncounterStage::AWAITING_RESULTS], true)) {
                $this->encounters->transition($encounter, EncounterStage::IN_CONSULTATION, __('Consultation started'));
            }

            $this->billing->chargeService($encounter, 'CONSULT', 1, $encounter);

            return $encounter->fresh();
        });
    }

    public function saveNote(Encounter $encounter, array $data, ?ClinicalNote $note = null): ClinicalNote
    {
        if ($note && $note->isSigned()) {
            throw ValidationException::withMessages(['note' => __('A signed note cannot be edited. Create an amendment instead.')]);
        }

        if ($note) {
            $note->update($data);

            return $note;
        }

        return ClinicalNote::create(array_merge($data, [
            'tenant_id' => $encounter->tenant_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'author_id' => Auth::id(),
            'status' => 'draft',
        ]));
    }

    public function sign(ClinicalNote $note): ClinicalNote
    {
        if ($note->author_id !== Auth::id()) {
            throw ValidationException::withMessages(['note' => __('Only the author can sign this note.')]);
        }

        if ($note->isSigned()) {
            return $note;
        }

        $note->update(['status' => 'signed', 'signed_at' => now()]);
        $this->audit->log('clinical_note.signed', $note);

        return $note;
    }

    public function amend(ClinicalNote $original, array $data, string $reason): ClinicalNote
    {
        return DB::transaction(function () use ($original, $data, $reason) {
            $amendment = ClinicalNote::create(array_merge($data, [
                'tenant_id' => $original->tenant_id,
                'patient_id' => $original->patient_id,
                'encounter_id' => $original->encounter_id,
                'author_id' => Auth::id(),
                'type' => $original->type,
                'status' => 'signed',
                'signed_at' => now(),
                'amends_note_id' => $original->id,
                'amendment_reason' => $reason,
            ]));

            $original->update(['status' => 'amended']);
            $this->audit->log('clinical_note.amended', $original, ['amendment_id' => $amendment->id, 'reason' => $reason]);

            return $amendment;
        });
    }

    public function addDiagnosis(Encounter $encounter, array $data): Diagnosis
    {
        return Diagnosis::create(array_merge($data, [
            'tenant_id' => $encounter->tenant_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'recorded_by' => Auth::id(),
        ]));
    }

    public function addAllergy(Encounter $encounter, array $data): PatientAllergy
    {
        $allergy = PatientAllergy::create(array_merge($data, [
            'tenant_id' => $encounter->tenant_id,
            'patient_id' => $encounter->patient_id,
            'recorded_by' => Auth::id(),
        ]));

        $this->audit->log('patient.allergy_recorded', $encounter->patient, ['allergen' => $allergy->allergen]);

        return $allergy;
    }
}
