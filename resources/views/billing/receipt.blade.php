<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $payment->receipt_number }}</title>
    <style>
        body { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; max-width: 320px; margin: 16px auto; color: #111; }
        h1 { font-size: 15px; text-align: center; margin: 0; }
        .c { text-align: center; } .r { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        hr { border: 0; border-top: 1px dashed #555; margin: 8px 0; }
        .big { font-size: 14px; font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <h1>{{ $tenant?->name }}</h1>
    <div class="c">{{ $facility?->name }}<br>{{ $tenant?->phone }} {{ $tenant?->email }}</div>
    <hr>
    <div class="c big">{{ __('OFFICIAL RECEIPT') }}</div>
    <table>
        <tr><td>{{ __('Receipt') }}</td><td class="r">{{ $payment->receipt_number }}</td></tr>
        <tr><td>{{ __('Date') }}</td><td class="r">{{ $payment->received_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td>{{ __('Invoice') }}</td><td class="r">{{ $payment->invoice->invoice_number }}</td></tr>
        <tr><td>{{ __('Patient') }}</td><td class="r">{{ $payment->invoice->patient->full_name }}</td></tr>
        <tr><td>{{ __('MRN') }}</td><td class="r">{{ $payment->invoice->patient->mrn }}</td></tr>
    </table>
    <hr>
    <table>
        @foreach ($payment->invoice->items as $item)
            <tr><td>{{ $item->description }}</td><td class="r">{{ number_format((float) $item->total, 2) }}</td></tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>{{ __('Invoice total') }}</td><td class="r">{{ number_format((float) $payment->invoice->total, 2) }}</td></tr>
        <tr class="big"><td>{{ __('Amount paid') }}</td><td class="r">{{ config('hms.currency') }} {{ number_format((float) $payment->amount, 2) }}</td></tr>
        <tr><td>{{ __('Method') }}</td><td class="r">{{ config('hms.payment_methods.'.$payment->method, $payment->method) }} {{ $payment->reference }}</td></tr>
        <tr><td>{{ __('Balance') }}</td><td class="r">{{ number_format((float) $payment->invoice->balance, 2) }}</td></tr>
    </table>
    <hr>
    <div class="c">{{ __('Served by') }}: {{ $payment->receivedBy?->name }}<br>{{ __('Thank you. Get well soon.') }}</div>
    <p class="c no-print"><button onclick="window.print()">{{ __('Print') }}</button></p>
</body>
</html>
