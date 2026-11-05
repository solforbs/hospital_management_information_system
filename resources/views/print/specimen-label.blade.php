<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('Label') }} {{ $item->specimen_number }}</title>
    <style>
        /* Standard 50 x 25 mm thermal specimen labels. */
        @page { size: 50mm 25mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; font-family: Arial, Helvetica, sans-serif; }
        .label { width: 50mm; height: 25mm; padding: 1.2mm 2mm; overflow: hidden; page-break-after: always; display: flex; flex-direction: column; }
        .label:last-child { page-break-after: auto; }
        .name { font-size: 8.5pt; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .meta { font-size: 6.5pt; display: flex; justify-content: space-between; gap: 1mm; white-space: nowrap; }
        .bar { flex: 1; margin-top: .6mm; min-height: 0; }
        .bar svg { width: 100%; height: 100%; display: block; }
        .sid { font-family: 'Courier New', monospace; font-size: 8pt; font-weight: 700; text-align: center; letter-spacing: .5px; }
        .screen-only { font: 13px Arial; padding: 12px; }
        @media print { .screen-only { display: none; } }
        @media screen { body { background: #eef1f8; } .label { background: #fff; margin: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.15); } }
    </style>
</head>
<body>
    <div class="screen-only">
        {{ __('Specimen label for :test — print on a 50 × 25 mm label printer.', ['test' => $item->test->name]) }}
        <button onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @for ($c = 0; $c < $copies; $c++)
        <div class="label">
            <div class="name">{{ $patient->last_name }}, {{ $patient->first_name }}</div>
            <div class="meta">
                <span>{{ $patient->mrn }}</span>
                <span>{{ strtoupper(substr((string) $patient->sex, 0, 1)) }} · {{ $patient->date_of_birth?->format('d/m/Y') }}</span>
            </div>
            <div class="meta">
                <span>{{ $item->test->code }} · {{ $item->test->specimen_type }}</span>
                <span>{{ $item->collected_at?->format('d/m H:i') }}</span>
            </div>
            <div class="bar">{!! \App\Support\Code128::svg($item->specimen_number) !!}</div>
            <div class="sid">{{ $item->specimen_number }}</div>
        </div>
    @endfor

    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
