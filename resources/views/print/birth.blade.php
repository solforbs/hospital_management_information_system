@php
    $labour = $newborn->labour;
    $mother = $newborn->mother;
@endphp
<x-print-document :tenant="$tenant" :facility="$facility" :title="__('Birth notification')" :number="$newborn->birth_notification_number ?? $labour->labour_number.'-'.$newborn->birth_order" :date="$labour->delivered_at" document="birth_notification">
    <h2>{{ __('Child') }}</h2>
    <div class="grid">
        <div><div class="label">{{ __('Name') }}</div><div class="value">{{ $newborn->baby?->full_name ?? '—' }}</div></div>
        <div><div class="label">{{ __('Record no.') }}</div><div class="value">{{ $newborn->baby?->mrn ?? '—' }}</div></div>
        <div><div class="label">{{ __('Sex') }}</div><div class="value">{{ ucfirst($newborn->sex) }}</div></div>
        <div><div class="label">{{ __('Date & time of birth') }}</div><div class="value">{{ $labour->delivered_at?->format('d M Y H:i') }}</div></div>
        <div><div class="label">{{ __('Birth weight') }}</div><div class="value">{{ $newborn->birth_weight_g ? number_format($newborn->birth_weight_g).' g' : '—' }}</div></div>
        <div><div class="label">{{ __('Type of birth') }}</div><div class="value">{{ $labour->newborns()->count() > 1 ? __('Multiple (:n of :t)', ['n' => $newborn->birth_order, 't' => $labour->newborns()->count()]) : __('Single') }}</div></div>
        <div><div class="label">{{ __('Mode of delivery') }}</div><div class="value">{{ \App\Models\Labour::MODES[$labour->delivery_mode] ?? $labour->delivery_mode }}</div></div>
        <div><div class="label">{{ __('Gestation') }}</div><div class="value">{{ $labour->gestation_weeks ? $labour->gestation_weeks.' '.__('weeks') : '—' }}</div></div>
        <div><div class="label">{{ __('Apgar 1′ / 5′') }}</div><div class="value">{{ $newborn->apgar_1 ?? '—' }} / {{ $newborn->apgar_5 ?? '—' }}</div></div>
    </div>

    <h2>{{ __('Mother') }}</h2>
    <div class="grid">
        <div><div class="label">{{ __('Name') }}</div><div class="value">{{ $mother?->full_name }}</div></div>
        <div><div class="label">{{ __('ID number') }}</div><div class="value">{{ $mother?->national_id ?? '—' }}</div></div>
        <div><div class="label">{{ __('Age') }}</div><div class="value">{{ $mother?->date_of_birth?->age ?? '—' }}</div></div>
        <div><div class="label">{{ __('Residence') }}</div><div class="value">{{ $mother?->county ?? '—' }}</div></div>
        <div><div class="label">{{ __('Phone') }}</div><div class="value">{{ $mother?->phone ?? '—' }}</div></div>
        <div><div class="label">{{ __('Parity') }}</div><div class="value">{{ $labour->pregnancy?->obstetricFormula() }}</div></div>
    </div>

    <div class="muted" style="margin-top: 10px;">{{ __('Delivered by') }}: <strong>{{ $labour->deliveredBy?->name ?? '—' }}</strong>. {{ __('Present this notification at the Civil Registration office to obtain a birth certificate.') }}</div>
</x-print-document>
