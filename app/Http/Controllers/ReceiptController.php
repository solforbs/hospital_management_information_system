<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\Tenancy\TenantContext;

/**
 * Printable payment receipt. Route-model binding goes through the
 * BelongsToTenant scope, so another tenant's receipt id 404s.
 */
class ReceiptController extends Controller
{
    public function __invoke(Payment $payment, TenantContext $context)
    {
        abort_unless(request()->user()->canAny(['billing.invoices.view', 'billing.payments.receive']), 403);

        $payment->load(['invoice.patient', 'invoice.items', 'receivedBy']);

        return view('billing.receipt', [
            'payment' => $payment,
            'tenant' => $context->tenant(),
            'facility' => $context->facility(),
        ]);
    }

    public function refund(Refund $refund, TenantContext $context)
    {
        abort_unless(request()->user()->canAny(['billing.invoices.view', 'billing.refunds.approve']), 403);

        $refund->load(['payment.invoice.patient', 'approver']);

        return view('billing.refund', ['refund' => $refund, 'tenant' => $context->tenant(), 'facility' => $context->facility()]);
    }
}
