<x-print-document :tenant="$tenant" :facility="$facility" :title="\App\Models\MedicalCertificate::TYPES[$certificate->type] ?? __('Medical certificate')" :number="$certificate->certificate_number" :date="$certificate->created_at" :patient="$certificate->patient" document="medical_certificate">
    <h2>{{ __('Certificate') }}</h2>
    <div class="box" style="font-size: 13px; line-height: 1.7;">
        @if ($certificate->type === 'sick_leave')
            {{ __('This is to certify that :name was seen at this facility and is medically unfit for duty / school for :days day(s), from :from to :to inclusive.', [
                'name' => $certificate->patient->full_name,
                'days' => $certificate->days,
                'from' => $certificate->from_date?->format('d M Y'),
                'to' => $certificate->to_date?->format('d M Y'),
            ]) }}
        @elseif ($certificate->type === 'fitness')
            {{ __('This is to certify that :name has been examined at this facility and is medically fit.', ['name' => $certificate->patient->full_name]) }}
        @else
            {{ __('This is to certify that :name attended this facility on :date.', ['name' => $certificate->patient->full_name, 'date' => ($certificate->from_date ?? $certificate->created_at)->format('d M Y')]) }}
        @endif
    </div>

    @if ($certificate->diagnosis_text)
        <h2>{{ __('Diagnosis') }}</h2>
        <div class="box">{{ $certificate->diagnosis_text }}</div>
    @endif

    @if ($certificate->remarks)
        <h2>{{ __('Remarks') }}</h2>
        <div class="box">{{ $certificate->remarks }}</div>
    @endif

    <div class="muted" style="margin-top: 10px;">{{ __('Issued by') }}: <strong>{{ $certificate->issuer?->name }}</strong>@if ($certificate->issuer?->registration_number) · {{ $certificate->issuer->regulator }} {{ $certificate->issuer->registration_number }}@endif</div>
</x-print-document>
