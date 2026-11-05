<?php

namespace App\Services\Diagnostics;

use App\Models\Encounter;
use App\Models\InvoiceItem;
use App\Models\LabInstrument;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabResultComponent;
use App\Models\LabTest;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Notifications\DepartmentNotifier;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Laboratory order lifecycle: order → specimen collection → result entry →
 * verification (by a different person where configured) → release to the
 * clinician. Each test is billed once, at order time, from the tenant catalog.
 */
class LaboratoryService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected BillingService $billing,
        protected DepartmentNotifier $notifier,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array<int>  $testIds
     */
    public function order(Encounter $encounter, array $testIds, string $priority = 'routine', ?string $notes = null): LabOrder
    {
        $this->assertOpen($encounter);

        $tests = LabTest::whereIn('id', $testIds)->where('active', true)->get();

        if ($tests->isEmpty()) {
            throw ValidationException::withMessages(['lab_tests' => __('Select at least one laboratory test.')]);
        }

        $order = DB::transaction(function () use ($encounter, $tests, $priority, $notes) {
            $order = LabOrder::create([
                'tenant_id' => $encounter->tenant_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'ordered_by' => Auth::id(),
                'order_number' => $this->numbers->next('LAB', $encounter->tenant_id),
                'priority' => $priority,
                'status' => 'ordered',
                'clinical_notes' => $notes,
            ]);

            foreach ($tests as $test) {
                $item = $order->items()->create([
                    'tenant_id' => $order->tenant_id,
                    'lab_test_id' => $test->id,
                    'status' => 'ordered',
                    'result_unit' => $test->unit,
                    'reference_range' => $test->referenceRange(),
                ]);

                $this->billing->charge($encounter, 'laboratory', $test->name, (float) $test->price, 1, $item);
            }

            return $order;
        });

        $this->notifier->notifyPermission(
            'lab.orders.process',
            __('New :priority lab order', ['priority' => $priority]),
            __(':patient — :tests', ['patient' => $encounter->patient->full_name, 'tests' => $tests->pluck('name')->join(', ')]),
            route('lab.worklist'),
            $priority === 'routine' ? 'info' : 'warning',
        );

        return $order;
    }

    public function collect(LabOrderItem $item): LabOrderItem
    {
        $this->assertStatus($item, ['ordered']);

        $item->update([
            'status' => 'collected',
            'specimen_number' => $this->numbers->next('SPM', $item->tenant_id),
            'collected_at' => now(),
            'collected_by' => Auth::id(),
        ]);

        $this->syncOrderStatus($item->order);

        return $item;
    }

    public function enterResult(LabOrderItem $item, string $value, ?string $comments = null): LabOrderItem
    {
        $this->assertStatus($item, ['collected', 'resulted']);

        $item->update([
            'status' => 'resulted',
            'result_value' => $value,
            'flag' => $this->flagFor($item->test, $value),
            'comments' => $comments,
            'resulted_at' => now(),
            'resulted_by' => Auth::id(),
            'resulted_by_instrument_id' => null, // a person has now entered/overridden it
        ]);

        $this->syncOrderStatus($item->order);

        return $item;
    }

    /**
     * Stores a result produced by an analyser. It lands as "resulted" (never
     * verified or released): a qualified person must still verify it. A
     * re-run before verification replaces the earlier instrument result.
     *
     * @param  list<array{name: string, value: string, unit: ?string, reference_range: ?string, flag: ?string}>  $components
     */
    public function recordInstrumentResult(LabOrderItem $item, LabInstrument $instrument, ?array $single, array $components): LabOrderItem
    {
        $this->assertStatus($item, ['collected', 'resulted']);

        return DB::transaction(function () use ($item, $instrument, $single, $components) {
            $test = $item->test;

            if ($components) {
                foreach ($components as $i => $c) {
                    LabResultComponent::updateOrCreate(
                        ['lab_order_item_id' => $item->id, 'name' => $c['name']],
                        ['tenant_id' => $item->tenant_id, 'value' => $c['value'], 'unit' => $c['unit'], 'reference_range' => $c['reference_range'], 'flag' => $c['flag'], 'sort' => $i],
                    );
                }

                $all = $item->components()->get();
                $value = Str::limit($all->map(fn ($c) => trim($c->name.' '.$c->value))->implode(' · '), 250);
                $flag = self::worstFlag($all->pluck('flag')->all());
                $unit = null;
                $range = null;
            } else {
                $value = $single['value'];
                $unit = $single['unit'] ?: $test->unit;
                $range = $single['reference_range'];
                $flag = $single['flag'] ?? $this->flagFor($test, $value);
            }

            $item->update([
                'status' => 'resulted',
                'result_value' => $value,
                'result_unit' => $unit ?? $item->result_unit,
                'reference_range' => $range ?? $item->reference_range,
                'flag' => $flag,
                'resulted_at' => now(),
                'resulted_by' => null,
                'resulted_by_instrument_id' => $instrument->id,
            ]);

            $this->audit->log('lab.result_from_instrument', $item, ['instrument' => $instrument->name, 'value' => $value, 'flag' => $flag]);
            $this->syncOrderStatus($item->order);

            return $item;
        });
    }

    /** critical > high/low > normal. */
    public static function worstFlag(array $flags): ?string
    {
        foreach (['critical', 'high', 'low', 'normal'] as $flag) {
            if (in_array($flag, $flags, true)) {
                return $flag;
            }
        }

        return null;
    }

    public function verify(LabOrderItem $item): LabOrderItem
    {
        $this->assertStatus($item, ['resulted']);

        if ($item->resulted_by === Auth::id() && config('hms.lab.require_independent_verification', true)) {
            throw ValidationException::withMessages(['verify' => __('Results must be verified by someone other than the person who entered them.')]);
        }

        $item->update(['status' => 'verified', 'verified_at' => now(), 'verified_by' => Auth::id()]);
        $this->audit->log('lab.result_verified', $item, ['value' => $item->result_value, 'flag' => $item->flag]);

        return $item;
    }

    public function release(LabOrderItem $item): LabOrderItem
    {
        $this->assertStatus($item, ['verified']);

        $item->update(['status' => 'released', 'released_at' => now()]);
        $this->audit->log('lab.result_released', $item);
        $this->syncOrderStatus($item->order);

        $order = $item->order;
        $critical = $item->flag === 'critical';

        $this->notifier->notifyUser(
            $order->orderedBy,
            $critical ? __('CRITICAL lab result') : __('Lab result released'),
            __(':test for :patient: :value :unit', [
                'test' => $item->test->name,
                'patient' => $order->patient->full_name,
                'value' => $item->result_value,
                'unit' => $item->result_unit,
            ]),
            route('consultation.show', $order->encounter_id),
            $critical ? 'danger' : 'success',
        );

        return $item;
    }

    public function cancel(LabOrderItem $item, string $reason): LabOrderItem
    {
        $this->assertStatus($item, ['ordered', 'collected']);

        DB::transaction(function () use ($item, $reason) {
            $item->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);

            $charge = InvoiceItem::where('source_type', $item->getMorphClass())->where('source_id', $item->id)->first();
            if ($charge) {
                $this->billing->removeCharge($charge, __('Lab test cancelled: :reason', ['reason' => $reason]));
            }

            $this->syncOrderStatus($item->order);
        });

        return $item;
    }

    public function flagFor(LabTest $test, string $value): ?string
    {
        if ($test->result_type !== 'numeric' || ! is_numeric($value) || $test->reference_low === null || $test->reference_high === null) {
            return null;
        }

        $v = (float) $value;
        $low = (float) $test->reference_low;
        $high = (float) $test->reference_high;

        return match (true) {
            $v < $low * 0.5 || $v > $high * 2 => 'critical',
            $v < $low => 'low',
            $v > $high => 'high',
            default => 'normal',
        };
    }

    protected function syncOrderStatus(LabOrder $order): void
    {
        $statuses = $order->items()->pluck('status');
        $active = $statuses->reject(fn ($s) => $s === 'cancelled');

        $status = match (true) {
            $active->isEmpty() => 'cancelled',
            $active->every(fn ($s) => $s === 'released') => 'completed',
            $active->every(fn ($s) => $s === 'ordered') => 'ordered',
            default => 'in_progress',
        };

        $order->update(['status' => $status]);
    }

    protected function assertStatus(LabOrderItem $item, array $allowed): void
    {
        if (! in_array($item->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('This test is :status and cannot be updated this way.', ['status' => $item->status])]);
        }
    }

    protected function assertOpen(Encounter $encounter): void
    {
        if (! $encounter->isOpen()) {
            throw ValidationException::withMessages(['encounter' => __('Orders can only be placed on an open visit.')]);
        }
    }
}
