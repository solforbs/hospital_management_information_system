@props(['tenant'])

@php
    $footer = $tenant?->setting('documents.document_footer');
    $hours = $tenant?->setting('documents.working_hours');
    $emergency = $tenant?->emergency_phone;
@endphp

@if ($footer || $hours || $emergency)
    <div {{ $attributes->merge(['style' => 'margin-top: 16px; padding-top: 8px; border-top: 1px dashed #CDD3E0; font-size: 10px; color: #6E768A; text-align: center;']) }}>
        @if ($hours)<div>{{ $hours }}</div>@endif
        @if ($emergency)<div>{{ __('Emergency') }}: <strong>{{ $emergency }}</strong></div>@endif
        @if ($footer)<div>{{ $footer }}</div>@endif
    </div>
@endif
