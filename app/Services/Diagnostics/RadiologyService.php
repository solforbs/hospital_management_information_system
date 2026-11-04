<?php

namespace App\Services\Diagnostics;

use App\Models\Encounter;
use App\Models\InvoiceItem;
use App\Models\RadiologyExam;
use App\Models\RadiologyOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Notifications\DepartmentNotifier;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Radiology lifecycle: order → schedule → perform → report → verify
 * (verification releases the report to the requesting clinician).
 */
class RadiologyService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected BillingService $billing,
        protected DepartmentNotifier $notifier,
        protected AuditLogger $audit,
    ) {}

    public function order(Encounter $encounter, int $examId, string $priority = 'routine', ?string $indication = null): RadiologyOrder
    {
        if (! $encounter->isOpen()) {
            throw ValidationException::withMessages(['encounter' => __('Orders can only be placed on an open visit.')]);
        }

        $exam = RadiologyExam::where('active', true)->findOrFail($examId);

        $order = DB::transaction(function () use ($encounter, $exam, $priority, $indication) {
            $order = RadiologyOrder::create([
                'tenant_id' => $encounter->tenant_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'radiology_exam_id' => $exam->id,
                'ordered_by' => Auth::id(),
                'order_number' => $this->numbers->next('RAD', $encounter->tenant_id),
                'priority' => $priority,
                'status' => 'ordered',
                'clinical_indication' => $indication,
            ]);

            $this->billing->charge($encounter, 'radiology', $exam->name, (float) $exam->price, 1, $order);

            return $order;
        });

        $this->notifier->notifyPermission(
            'radiology.orders.process',
            __('New imaging request'),
            __(':exam for :patient', ['exam' => $exam->name, 'patient' => $encounter->patient->full_name]),
            route('radiology.worklist'),
        );

        return $order;
    }

    public function schedule(RadiologyOrder $order, string $when): RadiologyOrder
    {
        $this->assertStatus($order, ['ordered', 'scheduled']);
        $order->update(['status' => 'scheduled', 'scheduled_at' => $when]);

        return $order;
    }

    public function perform(RadiologyOrder $order): RadiologyOrder
    {
        $this->assertStatus($order, ['ordered', 'scheduled']);
        $order->update(['status' => 'performed', 'performed_at' => now(), 'performed_by' => Auth::id()]);

        return $order;
    }

    public function report(RadiologyOrder $order, string $findings, string $impression): RadiologyOrder
    {
        $this->assertStatus($order, ['performed', 'reported']);
        $order->update([
            'status' => 'reported',
            'findings' => $findings,
            'impression' => $impression,
            'reported_at' => now(),
            'reported_by' => Auth::id(),
        ]);

        return $order;
    }

    public function verify(RadiologyOrder $order): RadiologyOrder
    {
        $this->assertStatus($order, ['reported']);
        $order->update(['status' => 'verified', 'verified_at' => now(), 'verified_by' => Auth::id()]);
        $this->audit->log('radiology.report_verified', $order);

        $this->notifier->notifyUser(
            $order->orderedBy,
            __('Imaging report available'),
            __(':exam for :patient: :impression', [
                'exam' => $order->exam->name,
                'patient' => $order->patient->full_name,
                'impression' => str($order->impression)->limit(80),
            ]),
            route('consultation.show', $order->encounter_id),
            'success',
        );

        return $order;
    }

    public function cancel(RadiologyOrder $order, string $reason): RadiologyOrder
    {
        $this->assertStatus($order, ['ordered', 'scheduled']);

        DB::transaction(function () use ($order, $reason) {
            $order->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);

            $charge = InvoiceItem::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->first();
            if ($charge) {
                $this->billing->removeCharge($charge, __('Imaging cancelled: :reason', ['reason' => $reason]));
            }
        });

        return $order;
    }

    protected function assertStatus(RadiologyOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('This request is :status and cannot be updated this way.', ['status' => $order->status])]);
        }
    }
}
