<?php

namespace App\Services\Pharmacy;

use App\Models\Encounter;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\PatientAllergy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\StockMovement;
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
 * Prescribing, stock receipt/adjustment and dispensing. Dispensing draws
 * stock first-expiry-first-out with row locks so concurrent dispensers
 * cannot drive stock negative, writes an append-only stock ledger, and
 * bills exactly what was dispensed.
 */
class PharmacyService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected BillingService $billing,
        protected DepartmentNotifier $notifier,
        protected AuditLogger $audit,
        protected EncounterService $encounters,
    ) {}

    /**
     * @param  array<int, array{medicine_id:int, dose:string, frequency:string, route:string, duration_days:int, quantity:int, instructions?:string}>  $items
     */
    public function prescribe(Encounter $encounter, array $items, ?string $notes = null): Prescription
    {
        if (! $encounter->isOpen()) {
            throw ValidationException::withMessages(['encounter' => __('Prescriptions can only be written on an open visit.')]);
        }

        if (empty($items)) {
            throw ValidationException::withMessages(['items' => __('Add at least one medicine.')]);
        }

        $prescription = DB::transaction(function () use ($encounter, $items, $notes) {
            $prescription = Prescription::create([
                'tenant_id' => $encounter->tenant_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'prescribed_by' => Auth::id(),
                'prescription_number' => $this->numbers->next('RX', $encounter->tenant_id),
                'status' => 'pending',
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $medicine = Medicine::where('active', true)->findOrFail($item['medicine_id']);

                $prescription->items()->create([
                    'tenant_id' => $prescription->tenant_id,
                    'medicine_id' => $medicine->id,
                    'dose' => $item['dose'],
                    'frequency' => $item['frequency'],
                    'route' => $item['route'] ?? 'oral',
                    'duration_days' => (int) $item['duration_days'],
                    'quantity' => (int) $item['quantity'],
                    'instructions' => $item['instructions'] ?? null,
                    'status' => 'pending',
                ]);
            }

            return $prescription;
        });

        $this->notifier->notifyPermission(
            'pharmacy.dispense',
            __('New prescription'),
            __(':number for :patient', ['number' => $prescription->prescription_number, 'patient' => $encounter->patient->full_name]),
            route('pharmacy.queue'),
        );

        return $prescription;
    }

    /**
     * Allergy check: returns matching allergy records (generic-name match)
     * so the prescriber/pharmacist sees a warning before proceeding.
     */
    public function allergyConflicts(int $patientId, Medicine $medicine)
    {
        return PatientAllergy::where('patient_id', $patientId)->get()->filter(function ($allergy) use ($medicine) {
            $a = strtolower($allergy->allergen);

            return str_contains(strtolower($medicine->generic_name), $a) || str_contains($a, strtolower($medicine->generic_name));
        });
    }

    /**
     * @param  array<int,int>  $quantities  prescription_item_id => quantity to dispense now
     */
    public function dispense(Prescription $prescription, array $quantities): Prescription
    {
        if (in_array($prescription->status, ['dispensed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['prescription' => __('This prescription is already :status.', ['status' => $prescription->status])]);
        }

        DB::transaction(function () use ($prescription, $quantities) {
            $encounter = $prescription->encounter;
            $minExpiry = now()->addDays((int) config('hms.pharmacy.min_days_to_expiry', 0))->toDateString();

            foreach ($prescription->items()->lockForUpdate()->get() as $item) {
                $qty = (int) ($quantities[$item->id] ?? 0);
                $remaining = $item->quantity - $item->quantity_dispensed;

                if ($qty <= 0 || $item->status === 'cancelled') {
                    continue;
                }

                if ($qty > $remaining) {
                    throw ValidationException::withMessages(["quantities.{$item->id}" => __('Cannot dispense more than the :remaining remaining.', ['remaining' => $remaining])]);
                }

                $batches = MedicineBatch::where('medicine_id', $item->medicine_id)
                    ->where('quantity_available', '>', 0)
                    ->whereDate('expiry_date', '>', $minExpiry)
                    ->orderBy('expiry_date')
                    ->lockForUpdate()
                    ->get();

                if ($batches->sum('quantity_available') < $qty) {
                    throw ValidationException::withMessages(["quantities.{$item->id}" => __('Insufficient in-date stock for :medicine (available: :available).', [
                        'medicine' => $item->medicine->displayName(),
                        'available' => $batches->sum('quantity_available'),
                    ])]);
                }

                $toTake = $qty;
                foreach ($batches as $batch) {
                    if ($toTake <= 0) {
                        break;
                    }
                    $take = min($toTake, $batch->quantity_available);
                    $batch->decrement('quantity_available', $take);

                    StockMovement::create([
                        'tenant_id' => $prescription->tenant_id,
                        'medicine_id' => $item->medicine_id,
                        'medicine_batch_id' => $batch->id,
                        'type' => 'dispense',
                        'quantity' => -$take,
                        'reference_type' => $item->getMorphClass(),
                        'reference_id' => $item->id,
                        'user_id' => Auth::id(),
                        'created_at' => now(),
                    ]);

                    $toTake -= $take;
                }

                $item->quantity_dispensed += $qty;
                $item->status = $item->quantity_dispensed >= $item->quantity ? 'dispensed' : 'partially_dispensed';
                $item->save();

                // Bill each dispensing event (a partial fill now, the balance
                // later). Over-billing is impossible because the quantity is
                // bounded by the locked remaining quantity above.
                $this->billing->charge(
                    $encounter,
                    'pharmacy',
                    $item->medicine->displayName().' x'.$qty,
                    (float) $item->medicine->unit_price,
                    $qty,
                );
            }

            $items = $prescription->items()->get();
            $active = $items->where('status', '!=', 'cancelled');
            $status = $active->every(fn ($i) => $i->status === 'dispensed') ? 'dispensed'
                : ($active->contains(fn ($i) => $i->quantity_dispensed > 0) ? 'partially_dispensed' : 'pending');

            $prescription->update([
                'status' => $status,
                'dispensed_at' => now(),
                'dispensed_by' => Auth::id(),
            ]);

            $this->audit->log('pharmacy.dispensed', $prescription, ['quantities' => $quantities]);

            if ($status === 'dispensed' && $encounter->isOpen() && $encounter->stage === EncounterStage::WAITING_PHARMACY) {
                $this->encounters->transition($encounter, EncounterStage::WAITING_BILLING, __('Medication dispensed'));
            }
        });

        return $prescription->fresh('items');
    }

    public function cancel(Prescription $prescription, string $reason): Prescription
    {
        if ($prescription->items()->where('quantity_dispensed', '>', 0)->exists()) {
            throw ValidationException::withMessages(['prescription' => __('Part of this prescription has been dispensed and cannot be cancelled.')]);
        }

        $prescription->items()->update(['status' => 'cancelled']);
        $prescription->update(['status' => 'cancelled', 'notes' => trim($prescription->notes."\n".__('Cancelled: :reason', ['reason' => $reason]))]);
        $this->audit->log('pharmacy.prescription_cancelled', $prescription, ['reason' => $reason]);

        return $prescription;
    }

    public function receiveStock(Medicine $medicine, array $data): MedicineBatch
    {
        return DB::transaction(function () use ($medicine, $data) {
            $batch = MedicineBatch::create([
                'tenant_id' => $medicine->tenant_id,
                'medicine_id' => $medicine->id,
                'batch_number' => $data['batch_number'],
                'expiry_date' => $data['expiry_date'],
                'quantity_received' => (int) $data['quantity'],
                'quantity_available' => (int) $data['quantity'],
                'unit_cost' => $data['unit_cost'] ?? 0,
                'supplier' => $data['supplier'] ?? null,
                'received_by' => Auth::id(),
            ]);

            StockMovement::create([
                'tenant_id' => $medicine->tenant_id,
                'medicine_id' => $medicine->id,
                'medicine_batch_id' => $batch->id,
                'type' => 'receipt',
                'quantity' => (int) $data['quantity'],
                'reason' => $data['supplier'] ?? null,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);

            return $batch;
        });
    }

    public function adjust(MedicineBatch $batch, int $delta, string $type, string $reason): MedicineBatch
    {
        return DB::transaction(function () use ($batch, $delta, $type, $reason) {
            $batch = MedicineBatch::lockForUpdate()->findOrFail($batch->id);

            if ($batch->quantity_available + $delta < 0) {
                throw ValidationException::withMessages(['adjust_quantity' => __('Adjustment would make stock negative.')]);
            }

            $batch->update(['quantity_available' => $batch->quantity_available + $delta]);

            StockMovement::create([
                'tenant_id' => $batch->tenant_id,
                'medicine_id' => $batch->medicine_id,
                'medicine_batch_id' => $batch->id,
                'type' => $type,
                'quantity' => $delta,
                'reason' => $reason,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);

            $this->audit->log('pharmacy.stock_adjusted', $batch, ['delta' => $delta, 'type' => $type, 'reason' => $reason]);

            return $batch;
        });
    }

    public function itemRemaining(PrescriptionItem $item): int
    {
        return max(0, $item->quantity - $item->quantity_dispensed);
    }
}
