<?php

namespace App\Services\Billing;

use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ServiceItem;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\NumberSequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All money flows through here. Prices always come from server-side
 * catalogs (never from the browser), invoice totals are recomputed from
 * their lines, and payments lock the invoice row so concurrent cashiers
 * cannot over-collect.
 */
class BillingService
{
    public function __construct(
        protected NumberSequenceService $numbers,
        protected AuditLogger $audit,
    ) {}

    public function invoiceFor(Encounter $encounter): Invoice
    {
        // A fully paid invoice is closed: later charges go on a new
        // (supplementary) invoice rather than reopening a settled one.
        $invoice = Invoice::where('encounter_id', $encounter->id)
            ->whereIn('status', ['open', 'partially_paid'])
            ->latest('id')
            ->first();

        if ($invoice) {
            return $invoice;
        }

        $insurance = $encounter->payer_type === 'insurance'
            ? $encounter->patient->insurances()->where('active', true)->latest('id')->first()
            : null;

        return Invoice::create([
            'tenant_id' => $encounter->tenant_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'patient_insurance_id' => $insurance?->id,
            'invoice_number' => $this->numbers->next('INV', $encounter->tenant_id),
            'payer_type' => $encounter->payer_type,
            'status' => 'open',
        ]);
    }

    /**
     * Charge an item to the encounter's invoice. When a $source model is
     * given the charge is idempotent: the same source can only be billed
     * once per category (enforced by a unique index as well).
     */
    public function charge(Encounter $encounter, string $category, string $description, float $unitPrice, float $quantity = 1, ?Model $source = null): ?InvoiceItem
    {
        return DB::transaction(function () use ($encounter, $category, $description, $unitPrice, $quantity, $source) {
            if ($source && InvoiceItem::where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('category', $category)
                ->exists()) {
                return null;
            }

            $invoice = $this->invoiceFor($encounter);

            $item = $invoice->items()->create([
                'tenant_id' => $invoice->tenant_id,
                'category' => $category,
                'description' => $description,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'total' => round($unitPrice * $quantity, 2),
                'added_by' => Auth::id(),
            ]);

            $this->recalculate($invoice);

            return $item;
        });
    }

    public function chargeService(Encounter $encounter, string $serviceCode, float $quantity = 1, ?Model $source = null): ?InvoiceItem
    {
        $service = ServiceItem::where('code', $serviceCode)->where('active', true)->first();

        if (! $service) {
            return null;
        }

        return $this->charge($encounter, $service->category, $service->name, (float) $service->price, $quantity, $source);
    }

    public function removeCharge(InvoiceItem $item, string $reason): void
    {
        DB::transaction(function () use ($item, $reason) {
            $invoice = Invoice::lockForUpdate()->findOrFail($item->invoice_id);

            if ((float) $invoice->amount_paid > 0 && $invoice->status === 'paid') {
                throw ValidationException::withMessages(['item' => __('Cannot remove a charge from a fully paid invoice.')]);
            }

            $this->audit->log('billing.charge_removed', $invoice, [
                'description' => $item->description, 'total' => $item->total, 'reason' => $reason,
            ]);

            $item->delete();
            $this->recalculate($invoice);
        });
    }

    public function recalculate(Invoice $invoice): Invoice
    {
        $subtotal = (float) $invoice->items()->sum('total');
        $paid = (float) $invoice->payments()->sum('amount');
        $discount = min((float) $invoice->discount, $subtotal);
        $total = round($subtotal - $discount, 2);
        $balance = round($total - $paid, 2);

        $status = $invoice->status === 'void' ? 'void' : match (true) {
            $total > 0 && $balance <= 0 => 'paid',
            $paid > 0 => 'partially_paid',
            default => 'open',
        };

        $invoice->update([
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => max($balance, 0),
            'status' => $status,
        ]);

        return $invoice;
    }

    public function receivePayment(Invoice $invoice, float $amount, string $method, ?string $reference = null): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $this->recalculate($invoice);

            if ($invoice->status === 'void') {
                throw ValidationException::withMessages(['amount' => __('This invoice has been voided.')]);
            }

            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('Payment amount must be greater than zero.')]);
            }

            if ($amount > (float) $invoice->balance) {
                throw ValidationException::withMessages(['amount' => __('Payment exceeds the outstanding balance of :balance.', ['balance' => number_format((float) $invoice->balance, 2)])]);
            }

            if (in_array($method, ['mpesa', 'card', 'bank_transfer'], true) && ! $reference) {
                throw ValidationException::withMessages(['reference' => __('A transaction reference is required for this payment method.')]);
            }

            $payment = $invoice->payments()->create([
                'tenant_id' => $invoice->tenant_id,
                'receipt_number' => $this->numbers->next('RCT', $invoice->tenant_id),
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'received_by' => Auth::id(),
                'received_at' => now(),
            ]);

            $this->recalculate($invoice);

            $this->audit->log('billing.payment_received', $invoice, [
                'receipt' => $payment->receipt_number, 'amount' => $amount, 'method' => $method,
            ]);

            return $payment;
        });
    }

    public function applyDiscount(Invoice $invoice, float $amount, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount, $reason) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($amount < 0 || $amount > (float) $invoice->subtotal) {
                throw ValidationException::withMessages(['discount' => __('Discount must be between 0 and the invoice subtotal.')]);
            }

            $invoice->update([
                'discount' => round($amount, 2),
                'discount_reason' => $reason,
                'discount_approved_by' => Auth::id(),
            ]);

            $this->audit->log('billing.discount_applied', $invoice, ['amount' => $amount, 'reason' => $reason]);

            return $this->recalculate($invoice);
        });
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->payments()->exists()) {
                throw ValidationException::withMessages(['void' => __('An invoice with payments cannot be voided.')]);
            }

            $invoice->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);
            $this->audit->log('billing.invoice_voided', $invoice, ['reason' => $reason]);

            return $invoice;
        });
    }
}
