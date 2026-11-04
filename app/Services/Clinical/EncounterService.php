<?php

namespace App\Services\Clinical;

use App\Jobs\PushEncounterToShr;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\EncounterTransition;
use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Hie\HieClient;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens encounters (check-in), moves them through the patient-flow
 * workflow with a full transition history, and closes them.
 */
class EncounterService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected TenantContext $context,
        protected AuditLogger $audit,
        protected BillingService $billing,
    ) {}

    public function checkIn(Patient $patient, array $data = [], ?Appointment $appointment = null): Encounter
    {
        if ($existing = $patient->activeEncounter()) {
            throw ValidationException::withMessages([
                'patient_id' => __('This patient already has an open visit (:number). Complete or cancel it first.', ['number' => $existing->encounter_number]),
            ]);
        }

        return DB::transaction(function () use ($patient, $data, $appointment) {
            $type = $data['type'] ?? 'outpatient';
            $initialStage = $data['stage'] ?? EncounterStage::WAITING_TRIAGE;

            $encounter = Encounter::create([
                'tenant_id' => $patient->tenant_id,
                'facility_id' => $this->context->facilityId() ?? $patient->facility_id,
                'patient_id' => $patient->id,
                'appointment_id' => $appointment?->id,
                'department_id' => $data['department_id'] ?? $appointment?->department_id,
                'attending_doctor_id' => $data['attending_doctor_id'] ?? $appointment?->doctor_id,
                'encounter_number' => $this->numbers->next('ENC', $patient->tenant_id),
                'type' => $type,
                'stage' => $initialStage,
                'status' => 'open',
                'priority' => $data['priority'] ?? ($type === 'emergency' ? 'emergency' : 'normal'),
                'triage_category' => $data['triage_category'] ?? null,
                'queue_number' => $this->numbers->nextQueueNumber($type === 'emergency' ? 'ER' : 'OPD', $patient->tenant_id),
                'payer_type' => $data['payer_type'] ?? 'cash',
                'chief_complaint' => $data['chief_complaint'] ?? $appointment?->reason,
                'created_by' => Auth::id(),
                'started_at' => now(),
            ]);

            $this->recordTransition($encounter, null, $initialStage, __('Checked in'));

            if ($appointment) {
                $appointment->update(['status' => 'checked_in', 'checked_in_at' => now()]);
            }

            $this->billing->chargeService($encounter, $type === 'emergency' ? 'ER-FEE' : 'REG-FEE', 1, $encounter);

            $this->audit->log('encounter.checked_in', $encounter, ['patient' => $patient->mrn]);

            return $encounter;
        });
    }

    public function transition(Encounter $encounter, string $toStage, ?string $reason = null): Encounter
    {
        if (! in_array($toStage, EncounterStage::all(), true)) {
            throw ValidationException::withMessages(['stage' => __('Unknown workflow stage.')]);
        }

        if (in_array($encounter->stage, EncounterStage::terminal(), true)) {
            throw ValidationException::withMessages(['stage' => __('This visit is already closed.')]);
        }

        if ($encounter->stage === $toStage) {
            return $encounter;
        }

        return DB::transaction(function () use ($encounter, $toStage, $reason) {
            $from = $encounter->stage;

            $attributes = ['stage' => $toStage];

            if ($toStage === EncounterStage::COMPLETED) {
                $attributes += ['status' => 'completed', 'completed_at' => now()];
            } elseif ($toStage === EncounterStage::CANCELLED) {
                $attributes += ['status' => 'cancelled', 'completed_at' => now()];
            }

            $encounter->update($attributes);
            $this->recordTransition($encounter, $from, $toStage, $reason);

            if ($encounter->appointment && in_array($toStage, EncounterStage::terminal(), true)) {
                $encounter->appointment->update(['status' => $toStage === EncounterStage::COMPLETED ? 'completed' : 'cancelled']);
            }

            return $encounter;
        });
    }

    public function complete(Encounter $encounter, ?string $reason = null): Encounter
    {
        $encounter = $this->transition($encounter, EncounterStage::COMPLETED, $reason ?? __('Visit completed'));
        $this->audit->log('encounter.completed', $encounter);

        // Share the finished visit with the national Shared Health Record.
        if (config('hms.hie.shr_auto_push') && app(HieClient::class)->isEnabled()) {
            $encounter->forceFill(['shr_status' => 'pending'])->save();
            PushEncounterToShr::dispatch($encounter->tenant_id, $encounter->id)->afterCommit();
        }

        return $encounter;
    }

    public function cancel(Encounter $encounter, string $reason): Encounter
    {
        if ($encounter->labOrders()->whereIn('status', ['in_progress', 'completed'])->exists()
            || $encounter->prescriptions()->whereIn('status', ['dispensed', 'partially_dispensed'])->exists()) {
            throw ValidationException::withMessages(['reason' => __('Services have already been delivered on this visit; complete it instead of cancelling.')]);
        }

        $encounter = $this->transition($encounter, EncounterStage::CANCELLED, $reason);
        $this->audit->log('encounter.cancelled', $encounter, ['reason' => $reason]);

        return $encounter;
    }

    public function assignDoctor(Encounter $encounter, ?int $doctorId): Encounter
    {
        $encounter->update(['attending_doctor_id' => $doctorId]);

        return $encounter;
    }

    protected function recordTransition(Encounter $encounter, ?string $from, string $to, ?string $reason): void
    {
        EncounterTransition::create([
            'tenant_id' => $encounter->tenant_id,
            'encounter_id' => $encounter->id,
            'from_stage' => $from,
            'to_stage' => $to,
            'department_id' => $encounter->department_id,
            'actor_id' => Auth::id(),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
