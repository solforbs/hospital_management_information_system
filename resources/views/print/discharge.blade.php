<x-print-document :tenant="$tenant" :facility="$facility" :title="__('Discharge summary')" :number="$admission->admission_number" :date="$admission->discharged_at" :patient="$admission->patient" document="discharge">
    <h2>{{ __('Admission') }}</h2>
    <div class="grid">
        <div><div class="label">{{ __('Admitted') }}</div><div class="value">{{ $admission->admitted_at?->format('d M Y H:i') }}</div></div>
        <div><div class="label">{{ __('Discharged') }}</div><div class="value">{{ $admission->discharged_at?->format('d M Y H:i') }}</div></div>
        <div><div class="label">{{ __('Length of stay') }}</div><div class="value">{{ $admission->lengthOfStayDays() }} {{ __('day(s)') }}</div></div>
        <div><div class="label">{{ __('Ward') }}</div><div class="value">{{ $admission->ward?->name }}</div></div>
        <div><div class="label">{{ __('Admitting doctor') }}</div><div class="value">{{ $admission->doctor?->name ?? '—' }}</div></div>
        <div><div class="label">{{ __('Discharge type') }}</div><div class="value">{{ ucfirst(str_replace('_', ' ', (string) $admission->discharge_type)) }}</div></div>
    </div>

    <h2>{{ __('Reason for admission') }}</h2>
    <div class="box">{{ $admission->reason }}</div>

    <h2>{{ __('Diagnoses') }}</h2>
    <table>
        <thead><tr><th>{{ __('ICD-11') }}</th><th>{{ __('Diagnosis') }}</th><th>{{ __('Type') }}</th></tr></thead>
        <tbody>
            @forelse ($admission->encounter?->diagnoses ?? [] as $d)
                <tr><td style="font-family: monospace;">{{ $d->code ?? '—' }}</td><td>{{ $d->description }}</td><td>{{ ucfirst($d->type) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">{{ __('None recorded') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($surgeries->isNotEmpty())
        <h2>{{ __('Procedures') }}</h2>
        <table>
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Procedure') }}</th><th>{{ __('Notes') }}</th></tr></thead>
            <tbody>
                @foreach ($surgeries as $s)
                    <tr><td>{{ $s->started_at?->format('d M Y') }}</td><td>{{ $s->procedure_name }}</td><td>{{ \Illuminate\Support\Str::limit((string) $s->operative_notes, 160) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('Summary of care and condition at discharge') }}</h2>
    <div class="box">{{ $admission->discharge_summary }}</div>

    @if ($medications->isNotEmpty())
        <h2>{{ __('Medication') }}</h2>
        <table>
            <thead><tr><th>{{ __('Medicine') }}</th><th>{{ __('Dose') }}</th><th>{{ __('Frequency') }}</th><th>{{ __('Duration') }}</th></tr></thead>
            <tbody>
                @foreach ($medications as $m)
                    <tr><td>{{ $m->medicine?->displayName() }}</td><td>{{ $m->dose }} {{ $m->route }}</td><td>{{ $m->frequency }}</td><td>{{ $m->duration_days }} {{ __('day(s)') }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($admission->follow_up_date)
        <h2>{{ __('Follow-up') }}</h2>
        <div class="box">{{ __('Review on :date', ['date' => $admission->follow_up_date->format('l, d M Y')]) }}</div>
    @endif
</x-print-document>
