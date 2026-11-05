@props(['tenant', 'facility' => null, 'title', 'number' => null, 'date' => null, 'patient' => null, 'document'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number ?? '' }} — {{ $tenant?->displayName() }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 12px; color: #33394C; margin: 0; background: #F5F7FB; }
        .sheet { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm; box-shadow: 0 4px 20px rgba(51,57,76,.08); }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; margin: 18px 0 6px; color: {{ $tenant?->brandColor('primary') ?? '#4E7CFF' }}; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 5px 6px; vertical-align: top; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #6E768A; border-bottom: 1px solid #CDD3E0; }
        td { border-bottom: 1px solid #EEF1F7; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 18px; }
        .label { font-size: 10px; color: #6E768A; text-transform: uppercase; letter-spacing: .05em; }
        .value { font-weight: 600; }
        .box { border: 1px solid #E3E7F0; border-radius: 8px; padding: 10px 12px; margin-top: 8px; white-space: pre-line; }
        .flag-H, .flag-HH, .flag-critical { color: #E2364B; font-weight: 700; }
        .flag-L, .flag-LL { color: #3D66E6; font-weight: 700; }
        .muted { color: #6E768A; }
        .no-print { text-align: center; margin: 12px; }
        @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; } .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="window.print()" style="padding: 8px 18px; border-radius: 8px; border: 0; background: #4E7CFF; color: #fff; font-weight: 600; cursor: pointer;">{{ __('Print') }}</button></div>
    <div class="sheet">
        <x-letterhead :tenant="$tenant" :facility="$facility" :title="$title" :number="$number ?? null" :date="$date ?? null" />

        @if ($patient)
            <div class="grid" style="margin-top: 14px;">
                <div><div class="label">{{ __('Patient') }}</div><div class="value">{{ $patient->full_name }}</div></div>
                <div><div class="label">{{ __('MRN') }}</div><div class="value">{{ $patient->mrn }}</div></div>
                <div><div class="label">{{ __('Sex / Age') }}</div><div class="value">{{ ucfirst($patient->sex) }} · {{ $patient->date_of_birth ? $patient->date_of_birth->age.' '.__('yrs') : '—' }}</div></div>
                <div><div class="label">{{ __('Date of birth') }}</div><div class="value">{{ $patient->date_of_birth?->format('d M Y') ?? '—' }}</div></div>
                <div><div class="label">{{ __('ID number') }}</div><div class="value">{{ $patient->national_id ?? '—' }}</div></div>
                <div><div class="label">{{ __('SHA / CR no.') }}</div><div class="value">{{ $patient->sha_number ?? $patient->cr_number ?? '—' }}</div></div>
            </div>
        @endif

        {{ $slot }}

        <x-signatures :tenant="$tenant" :document="$document" />
        <x-document-footer :tenant="$tenant" />
        <div class="muted" style="margin-top: 10px; font-size: 9px; text-align: right;">{{ __('Printed') }} {{ now()->format('d M Y H:i') }} {{ __('by') }} {{ auth()->user()?->name }}</div>
    </div>
</body>
</html>
