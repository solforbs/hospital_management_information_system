<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Refund voucher') }} {{ $refund->refund_number }}</title>
    <style>
        body { font-family: system-ui, sans-serif; font-size: 12px; max-width: 340px; margin: 16px auto; color: #111; }
        .c { text-align: center; } .r { text-align: right; }
        table { width: 100%; border-collapse: collapse; } td { padding: 2px 0; vertical-align: top; }
        hr { border: 0; border-top: 1px dashed #555; margin: 8px 0; }
        .big { font-size: 14px; font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <x-letterhead :tenant="$tenant" :facility="$facility" compact />
    <hr>
    <div class="c big">{{ __('REFUND VOUCHER') }}</div>
    <table>
        <tr><td>{{ __('Voucher') }}</td><td class="r">{{ $refund->refund_number }}</td></tr>
        <tr><td>{{ __('Date') }}</td><td class="r">{{ $refund->created_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td>{{ __('Original receipt') }}</td><td class="r">{{ $refund->payment->receipt_number }}</td></tr>
        <tr><td>{{ __('Invoice') }}</td><td class="r">{{ $refund->payment->invoice->invoice_number }}</td></tr>
        <tr><td>{{ __('Patient') }}</td><td class="r">{{ $refund->payment->invoice->patient->full_name }}</td></tr>
    </table>
    <hr>
    <table>
        <tr class="big"><td>{{ __('Amount refunded') }}</td><td class="r">{{ config('hms.currency') }} {{ number_format((float) $refund->amount, 2) }}</td></tr>
        <tr><td>{{ __('Method') }}</td><td class="r">{{ config('hms.payment_methods.'.$refund->method, $refund->method) }} {{ $refund->reference }}</td></tr>
        <tr><td>{{ __('Reason') }}</td><td class="r">{{ $refund->reason }}</td></tr>
        <tr><td>{{ __('Approved by') }}</td><td class="r">{{ $refund->approver?->name }}</td></tr>
    </table>
    <hr>
    <p>{{ __('Received by (name & signature)') }}: ______________________</p>
    <x-signatures :tenant="$tenant" document="receipt" style="justify-content: center;" />
    <p class="c no-print"><button onclick="window.print()">{{ __('Print') }}</button></p>
</body>
</html>
