<x-print-document :tenant="$tenant" :facility="$facility" :title="__('Laboratory report')" :number="$order->order_number" :date="$items->max('released_at')" :patient="$order->patient" document="lab_report">
    <div class="grid" style="margin-top: 10px;">
        <div><div class="label">{{ __('Requested by') }}</div><div class="value">{{ $order->orderedBy?->name ?? '—' }}</div></div>
        <div><div class="label">{{ __('Requested') }}</div><div class="value">{{ $order->created_at->format('d M Y H:i') }}</div></div>
        <div><div class="label">{{ __('Priority') }}</div><div class="value">{{ ucfirst($order->priority) }}</div></div>
    </div>
    @if ($order->clinical_notes)<div class="box">{{ __('Clinical notes') }}: {{ $order->clinical_notes }}</div>@endif

    <h2>{{ __('Results') }}</h2>
    <table>
        <thead><tr><th>{{ __('Test') }}</th><th>{{ __('Result') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Reference range') }}</th><th>{{ __('Flag') }}</th><th>{{ __('Specimen') }}</th></tr></thead>
        <tbody>
            @foreach ($items as $i)
                <tr>
                    <td><strong>{{ $i->test?->name }}</strong>@if ($i->comments)<div class="muted">{{ $i->comments }}</div>@endif</td>
                    <td class="flag-{{ $i->flag }}" style="font-weight: 700;">{{ $i->result_value }}</td>
                    <td>{{ $i->result_unit }}</td>
                    <td>{{ $i->reference_range }}</td>
                    <td class="flag-{{ $i->flag }}">{{ $i->flag && $i->flag !== 'N' ? strtoupper($i->flag) : '' }}</td>
                    <td class="muted">{{ $i->specimen_number }}<br>{{ $i->collected_at?->format('d M H:i') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="muted" style="margin-top: 8px; font-size: 10px;">
        {{ __('Performed by') }}: {{ $items->pluck('resultedBy.name')->filter()->unique()->implode(', ') ?: '—' }}
        · {{ __('Verified by') }}: {{ $items->pluck('verifiedBy.name')->filter()->unique()->implode(', ') ?: '—' }}
    </div>
</x-print-document>
