<?php

namespace App\Services\Clinical;

use App\Models\Admission;
use App\Models\BloodIssue;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\DeathNotification;
use App\Models\DialysisSession;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\SpecialtyVisit;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Tenancy\TenantContext;
use App\Support\SpecialtyClinics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Level 5–6 departments: mortuary, blood bank, dialysis and specialist clinics.
 */
class DepartmentService
{
    /** Red-cell compatibility: recipient ABO => donor ABO groups allowed. */
    protected const RBC_COMPATIBLE = ['O' => ['O'], 'A' => ['A', 'O'], 'B' => ['B', 'O'], 'AB' => ['AB', 'A', 'B', 'O']];

    /** Plasma compatibility (FFP/cryo): recipient => donor. */
    protected const PLASMA_COMPATIBLE = ['O' => ['O', 'A', 'B', 'AB'], 'A' => ['A', 'AB'], 'B' => ['B', 'AB'], 'AB' => ['AB']];

    public function __construct(
        protected NumberSequenceService $numbers,
        protected AuditLogger $audit,
        protected BillingService $billing,
        protected EncounterService $encounters,
        protected TenantContext $context,
    ) {}

    // ------------------------------------------------------------ Mortuary

    public function recordDeath(Patient $patient, array $data, ?Admission $admission = null): DeathNotification
    {
        if (DeathNotification::where('patient_id', $patient->id)->exists()) {
            throw ValidationException::withMessages(['death' => __('A death has already been recorded for this patient.')]);
        }

        $diedAt = Carbon::parse($data['died_at']);
        if ($diedAt->isFuture()) {
            throw ValidationException::withMessages(['died_at' => __('Time of death cannot be in the future.')]);
        }

        $death = DeathNotification::create([
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $this->context->facilityId(),
            'patient_id' => $patient->id,
            'encounter_id' => $admission?->encounter_id ?? $patient->activeEncounter()?->id,
            'admission_id' => $admission?->id,
            'notification_number' => $this->numbers->next('DN', $patient->tenant_id),
            'died_at' => $diedAt,
            'place' => $data['place'],
            'immediate_cause' => $data['immediate_cause'],
            'immediate_icd' => $data['immediate_icd'] ?: null,
            'underlying_cause' => $data['underlying_cause'] ?: null,
            'underlying_icd' => $data['underlying_icd'] ?: null,
            'other_conditions' => $data['other_conditions'] ?: null,
            'manner' => $data['manner'] ?? 'natural',
            'is_maternal' => (bool) ($data['is_maternal'] ?? false),
            'autopsy_requested' => (bool) ($data['autopsy_requested'] ?? false),
            'certified_by' => Auth::id(),
            'body_status' => 'in_ward',
        ]);

        $patient->forceFill(['deceased_at' => $diedAt])->save();
        $this->audit->log('mortuary.death_recorded', $death);

        return $death;
    }

    public function admitToMortuary(DeathNotification $death, string $storageUnit): DeathNotification
    {
        if ($death->body_status !== 'in_ward') {
            throw ValidationException::withMessages(['storage_unit' => __('The body is already in the mortuary or released.')]);
        }

        if (DeathNotification::where('storage_unit', $storageUnit)->where('body_status', 'in_mortuary')->exists()) {
            throw ValidationException::withMessages(['storage_unit' => __('Storage unit :u is occupied.', ['u' => $storageUnit])]);
        }

        $death->update(['body_status' => 'in_mortuary', 'storage_unit' => $storageUnit, 'mortuary_admitted_at' => now()]);
        $this->audit->log('mortuary.admitted', $death, ['unit' => $storageUnit]);

        return $death;
    }

    public function releaseBody(DeathNotification $death, array $data): DeathNotification
    {
        if ($death->body_status === 'released') {
            throw ValidationException::withMessages(['released_to' => __('The body has already been released.')]);
        }

        return DB::transaction(function () use ($death, $data) {
            $death->update([
                'body_status' => 'released',
                'released_to' => $data['released_to'],
                'released_to_id_number' => $data['released_to_id_number'],
                'released_to_relationship' => $data['released_to_relationship'],
                'burial_permit_number' => $data['burial_permit_number'] ?: null,
                'released_at' => now(),
                'released_by' => Auth::id(),
            ]);

            // Mortuary storage is billed per day on release.
            $encounter = $death->encounter_id ? Encounter::find($death->encounter_id) : null;
            if ($encounter && $death->mortuary_admitted_at && ! in_array($encounter->stage, ['cancelled'], true)) {
                $this->billing->chargeService($encounter, 'MORTUARY-DAY', $death->storageDays(), $death);
            }

            $this->audit->log('mortuary.released', $death, ['to' => $data['released_to']]);

            return $death;
        });
    }

    // ---------------------------------------------------------- Blood bank

    public function receiveUnit(array $data): BloodUnit
    {
        if (Carbon::parse($data['expires_on'])->isPast()) {
            throw ValidationException::withMessages(['expires_on' => __('This unit has already expired.')]);
        }

        $unit = BloodUnit::create($data + ['status' => 'available', 'received_by' => Auth::id()]);
        $this->audit->log('bloodbank.unit_received', $unit);

        return $unit;
    }

    public function requestBlood(Patient $patient, array $data): BloodRequest
    {
        return BloodRequest::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'encounter_id' => $patient->activeEncounter()?->id,
            'request_number' => $this->numbers->next('BR', $patient->tenant_id),
            'patient_abo' => $data['patient_abo'] ?: null,
            'patient_rh' => $data['patient_rh'] ?: null,
            'component' => $data['component'],
            'units_requested' => (int) $data['units_requested'],
            'urgency' => $data['urgency'],
            'indication' => $data['indication'],
            'status' => 'pending',
            'requested_by' => Auth::id(),
        ]);
    }

    /** Units compatible with the recipient, nearest expiry first. */
    public function compatibleUnits(BloodRequest $request)
    {
        if (! $request->patient_abo || ! $request->patient_rh) {
            return collect();
        }

        $plasma = in_array($request->component, ['ffp', 'cryo'], true);
        $abo = ($plasma ? self::PLASMA_COMPATIBLE : self::RBC_COMPATIBLE)[$request->patient_abo] ?? [];

        return BloodUnit::where('status', 'available')
            ->where('component', $request->component)
            ->whereIn('abo', $abo)
            ->when(! $plasma && $request->patient_rh === 'neg', fn ($q) => $q->where('rh', 'neg'))
            ->where('expires_on', '>=', now()->toDateString())
            ->orderBy('expires_on')
            ->get();
    }

    /** Issue a unit after a compatible crossmatch; refuses ABO/Rh-incompatible or expired units. */
    public function issueUnit(BloodRequest $request, BloodUnit $unit, string $crossmatch): BloodIssue
    {
        if ($crossmatch !== 'compatible') {
            throw ValidationException::withMessages(['crossmatch' => __('Only crossmatch-compatible units can be issued.')]);
        }

        return DB::transaction(function () use ($request, $unit, $crossmatch) {
            $unit = BloodUnit::lockForUpdate()->findOrFail($unit->id);

            if (! $this->compatibleUnits($request)->contains('id', $unit->id)) {
                throw ValidationException::withMessages(['unit' => __('Unit :u is not available or not ABO/Rh compatible with this patient.', ['u' => $unit->unit_number])]);
            }

            $unit->update(['status' => 'issued', 'issued_at' => now()]);
            $issue = BloodIssue::create([
                'tenant_id' => $request->tenant_id,
                'blood_request_id' => $request->id,
                'blood_unit_id' => $unit->id,
                'crossmatch' => $crossmatch,
                'issued_at' => now(),
                'issued_by' => Auth::id(),
            ]);

            $issued = $request->issues()->count();
            $request->update(['status' => $issued >= $request->units_requested ? 'issued' : 'crossmatched']);

            if ($request->encounter_id && ($encounter = Encounter::find($request->encounter_id))) {
                $this->billing->chargeService($encounter, 'BLOOD-UNIT', 1, $issue);
            }

            $this->audit->log('bloodbank.unit_issued', $issue, ['unit' => $unit->unit_number]);

            return $issue;
        });
    }

    public function recordReaction(BloodIssue $issue, string $reaction, ?string $notes): BloodIssue
    {
        $issue->update(['reaction' => $reaction, 'reaction_notes' => $notes]);
        $this->audit->log('bloodbank.reaction', $issue, ['reaction' => $reaction]);

        return $issue;
    }

    public function discardUnit(BloodUnit $unit, string $reason): BloodUnit
    {
        if ($unit->status === 'issued') {
            throw ValidationException::withMessages(['unit' => __('An issued unit cannot be discarded.')]);
        }

        $unit->update(['status' => 'discarded', 'discard_reason' => $reason]);

        return $unit;
    }

    // ------------------------------------------------------------ Dialysis

    public function startDialysis(Patient $patient, array $data): DialysisSession
    {
        if (DialysisSession::where('patient_id', $patient->id)->where('status', 'in_progress')->exists()) {
            throw ValidationException::withMessages(['dialysis' => __('This patient already has a session in progress.')]);
        }

        $encounter = $patient->activeEncounter() ?? $this->encounters->checkIn($patient, ['chief_complaint' => __('Haemodialysis'), 'payer_type' => $data['payer_type'] ?? 'cash']);

        return DialysisSession::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'session_number' => $this->numbers->next('HD', $patient->tenant_id),
            'modality' => $data['modality'] ?? 'hd',
            'machine' => $data['machine'] ?: null,
            'access' => $data['access'] ?: null,
            'started_at' => now(),
            'pre_weight_kg' => $data['pre_weight_kg'] ?: null,
            'pre_systolic' => $data['pre_systolic'] ?: null,
            'pre_diastolic' => $data['pre_diastolic'] ?: null,
            'uf_goal_ml' => $data['uf_goal_ml'] ?: null,
            'heparin' => $data['heparin'] ?: null,
            'status' => 'in_progress',
            'nurse_id' => Auth::id(),
        ]);
    }

    public function endDialysis(DialysisSession $session, array $data, bool $aborted = false): DialysisSession
    {
        if ($session->status !== 'in_progress') {
            throw ValidationException::withMessages(['dialysis' => __('This session is already closed.')]);
        }

        $session->update([
            'ended_at' => now(),
            'post_weight_kg' => $data['post_weight_kg'] ?: null,
            'post_systolic' => $data['post_systolic'] ?: null,
            'post_diastolic' => $data['post_diastolic'] ?: null,
            'uf_achieved_ml' => $data['uf_achieved_ml'] ?: null,
            'complications' => $data['complications'] ?: null,
            'status' => $aborted ? 'aborted' : 'completed',
        ]);

        if ($session->encounter_id && ($encounter = Encounter::find($session->encounter_id))) {
            $this->billing->chargeService($encounter, 'DIALYSIS-SESSION', 1, $session);
        }

        $this->audit->log('dialysis.'.($aborted ? 'aborted' : 'completed'), $session);

        return $session;
    }

    // --------------------------------------------------- Specialist clinics

    public function recordClinicVisit(string $clinic, Patient $patient, array $data, ?string $notes, ?string $nextVisit): SpecialtyVisit
    {
        $def = SpecialtyClinics::find($clinic);
        if (! $def) {
            throw ValidationException::withMessages(['clinic' => __('Unknown clinic.')]);
        }

        $encounter = $patient->activeEncounter();

        $visit = SpecialtyVisit::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'clinic' => $clinic,
            'data' => array_filter($data, fn ($v) => $v !== '' && $v !== null) ?: null,
            'notes' => $notes ?: null,
            'next_visit_on' => $nextVisit ?: null,
            'seen_by' => Auth::id(),
        ]);

        if ($encounter) {
            $this->billing->chargeService($encounter, $def['service_code'], 1, $visit);
        }

        $this->audit->log('clinic.visit', $visit, ['clinic' => $clinic]);

        return $visit;
    }
}
