<x-print-document :tenant="$tenant" :facility="$facility" :title="__('Radiology report')" :number="$order->order_number" :date="$order->verified_at" :patient="$order->patient" document="radiology_report">
    <div class="grid" style="margin-top: 10px;">
        <div><div class="label">{{ __('Examination') }}</div><div class="value">{{ $order->exam?->name }}</div></div>
        <div><div class="label">{{ __('Modality') }}</div><div class="value">{{ strtoupper((string) $order->exam?->modality) }}</div></div>
        <div><div class="label">{{ __('Performed') }}</div><div class="value">{{ $order->performed_at?->format('d M Y H:i') ?? '—' }}</div></div>
        <div><div class="label">{{ __('Requested by') }}</div><div class="value">{{ $order->orderedBy?->name ?? '—' }}</div></div>
        <div><div class="label">{{ __('Reported by') }}</div><div class="value">{{ $order->reportedBy?->name ?? '—' }}</div></div>
    </div>

    @if ($order->clinical_indication)
        <h2>{{ __('Clinical indication') }}</h2>
        <div class="box">{{ $order->clinical_indication }}</div>
    @endif

    <h2>{{ __('Findings') }}</h2>
    <div class="box">{{ $order->findings }}</div>

    <h2>{{ __('Impression') }}</h2>
    <div class="box" style="font-weight: 600;">{{ $order->impression }}</div>
</x-print-document>
