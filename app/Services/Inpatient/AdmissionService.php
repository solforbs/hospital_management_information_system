<?php

namespace App\Services\Inpatient;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\Encounter;
use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\ServiceItem;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Clinical\EncounterService;
use App\Services\Notifications\DepartmentNotifier;
use App\Services\Numbering\NumberSequenceService;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inpatient lifecycle: admission request → bed allocation → transfers →
 * discharge (bills bed-days, sends the bed to cleaning) → bed release.
 * Bed rows are locked during allocation so a bed cannot be double-booked.
 */
class AdmissionService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected BillingService $billing,
        protected EncounterService $encounters,
        protected DepartmentNotifier $notifier,
        protected AuditLogger $audit,
    ) {}

    public function request(Encounter $encounter, array $data): Admission
    {
        if (! $encounter->isOpen()) {
            throw ValidationException::withMessages(['encounter' => __('Admission can only be requested from an open visit.')]);
        }

        if (Admission::where('encounter_id', $encounter->id)->whereIn('status', ['requested', 'admitted'])->exists()) {
            throw ValidationException::withMessages(['reason' => __('This visit already has an active admission.')]);
        }

        $admission = Admission::create([
            'tenant_id' => $encounter->tenant_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'ward_id' => $data['ward_id'] ?? null,
            'admitting_doctor_id' => Auth::id(),
            'admission_number' => $this->numbers->next('ADM', $encounter->tenant_id),
            'type' => $data['type'] ?? 'planned',
            'status' => 'requested',
            'reason' => $data['reason'],
            'requested_by' => Auth::id(),
            'requested_at' => now(),
        ]);

        $this->notifier->notifyPermission(
            'wards.beds.allocate',
            __('Admission requested'),
            __(':patient — :reason', ['patient' => $encounter->patient->full_name, 'reason' => str($data['reason'])->limit(60)]),
            route('wards.admissions'),
            'warning',
        );

        return $admission;
    }

    public function admit(Admission $admission, int $bedId): Admission
    {
        if ($admission->status !== 'requested') {
            throw ValidationException::withMessages(['bed_id' => __('Only a requested admission can be admitted.')]);
        }

        return DB::transaction(function () use ($admission, $bedId) {
            $bed = Bed::lockForUpdate()->findOrFail($bedId);

            if ($bed->status !== 'available') {
                throw ValidationException::withMessages(['bed_id' => __('Bed :bed is not available.', ['bed' => $bed->bed_number])]);
            }

            $bed->update(['status' => 'occupied']);

            BedAssignment::create([
                'tenant_id' => $admission->tenant_id,
                'admission_id' => $admission->id,
                'bed_id' => $bed->id,
                'assigned_at' => now(),
                'assigned_by' => Auth::id(),
                'reason' => __('Initial admission'),
            ]);

            $admission->update([
                'status' => 'admitted',
                'ward_id' => $bed->ward_id,
                'bed_id' => $bed->id,
                'admitted_at' => now(),
                'admitted_by' => Auth::id(),
            ]);

            $encounter = $admission->encounter;
            $encounter->update(['type' => 'inpatient']);
            $this->encounters->transition($encounter, EncounterStage::ADMITTED, __('Admitted to :ward bed :bed', ['ward' => $bed->ward->name, 'bed' => $bed->bed_number]));

            $this->audit->log('admission.admitted', $admission, ['bed' => $bed->bed_number]);

            return $admission;
        });
    }

    public function transfer(Admission $admission, int $bedId, string $reason): Admission
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['transfer_bed_id' => __('Only an admitted patient can be transferred.')]);
        }

        return DB::transaction(function () use ($admission, $bedId, $reason) {
            $newBed = Bed::lockForUpdate()->findOrFail($bedId);

            if ($newBed->status !== 'available') {
                throw ValidationException::withMessages(['transfer_bed_id' => __('Bed :bed is not available.', ['bed' => $newBed->bed_number])]);
            }

            if ($admission->bed_id) {
                Bed::whereKey($admission->bed_id)->update(['status' => 'cleaning']);
                BedAssignment::where('admission_id', $admission->id)->whereNull('released_at')->update(['released_at' => now()]);
            }

            $newBed->update(['status' => 'occupied']);

            BedAssignment::create([
                'tenant_id' => $admission->tenant_id,
                'admission_id' => $admission->id,
                'bed_id' => $newBed->id,
                'assigned_at' => now(),
                'assigned_by' => Auth::id(),
                'reason' => $reason,
            ]);

            $admission->update(['ward_id' => $newBed->ward_id, 'bed_id' => $newBed->id]);
            $this->audit->log('admission.transferred', $admission, ['to_bed' => $newBed->bed_number, 'reason' => $reason]);

            return $admission;
        });
    }

    public function discharge(Admission $admission, array $data): Admission
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['discharge_summary' => __('Only an admitted patient can be discharged.')]);
        }

        return DB::transaction(function () use ($admission, $data) {
            $encounter = $admission->encounter;

            // Bill bed-days for each ward stay segment.
            $days = $admission->lengthOfStayDays();
            $rate = (float) ($admission->ward?->daily_rate ?: ServiceItem::where('code', 'BED-DAY')->value('price') ?: 0);

            if ($rate > 0) {
                $this->billing->charge($encounter, 'bed', __('Bed charges — :ward (:days day(s))', ['ward' => $admission->ward?->name, 'days' => $days]), $rate, $days, $admission);
            }

            if (config('hms.billing.require_settlement_before_discharge')) {
                $outstanding = $encounter->invoices()->whereIn('status', ['open', 'partially_paid'])->sum('balance');

                if ($outstanding > 0 && $encounter->payer_type === 'cash') {
                    throw ValidationException::withMessages(['discharge_summary' => __('Outstanding balance of :amount must be settled before discharge.', ['amount' => number_format($outstanding, 2)])]);
                }
            }

            if ($admission->bed_id) {
                Bed::whereKey($admission->bed_id)->update(['status' => 'cleaning']);
                BedAssignment::where('admission_id', $admission->id)->whereNull('released_at')->update(['released_at' => now()]);
            }

            $admission->update([
                'status' => 'discharged',
                'discharged_at' => now(),
                'discharged_by' => Auth::id(),
                'discharge_type' => $data['discharge_type'],
                'discharge_summary' => $data['discharge_summary'],
                'follow_up_date' => $data['follow_up_date'] ?: null,
            ]);

            $this->encounters->transition($encounter, EncounterStage::WAITING_BILLING, __('Discharged — final billing'));
            $this->audit->log('admission.discharged', $admission, ['type' => $data['discharge_type'], 'days' => $days]);

            return $admission;
        });
    }

    public function cancel(Admission $admission, string $reason): Admission
    {
        if ($admission->status !== 'requested') {
            throw ValidationException::withMessages(['reason' => __('Only a pending request can be cancelled.')]);
        }

        $admission->update(['status' => 'cancelled', 'discharge_summary' => $reason]);

        return $admission;
    }

    public function markBedAvailable(Bed $bed): Bed
    {
        if (! in_array($bed->status, ['cleaning', 'maintenance'], true)) {
            throw ValidationException::withMessages(['bed' => __('Only a bed being cleaned or under maintenance can be released.')]);
        }

        $bed->update(['status' => 'available']);

        return $bed;
    }

    public function addNursingNote(Admission $admission, string $note, ?string $shift): NursingNote
    {
        return NursingNote::create([
            'tenant_id' => $admission->tenant_id,
            'admission_id' => $admission->id,
            'author_id' => Auth::id(),
            'shift' => $shift,
            'note' => $note,
        ]);
    }

    public function recordAdministration(Admission $admission, array $data): MedicationAdministration
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['mar_medicine_id' => __('Medication can only be charted for an admitted patient.')]);
        }

        return MedicationAdministration::create([
            'tenant_id' => $admission->tenant_id,
            'admission_id' => $admission->id,
            'prescription_item_id' => $data['prescription_item_id'] ?? null,
            'medicine_id' => $data['medicine_id'],
            'dose' => $data['dose'],
            'route' => $data['route'] ?? 'oral',
            'status' => $data['status'] ?? 'given',
            'notes' => $data['notes'] ?? null,
            'administered_by' => Auth::id(),
            'administered_at' => now(),
        ]);
    }
}
