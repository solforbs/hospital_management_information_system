@props(['category'])

@php
    $map = [
        'red' => ['red', __('Immediate')],
        'orange' => ['orange', __('Very urgent')],
        'yellow' => ['yellow', __('Urgent')],
        'green' => ['lime', __('Standard')],
        'blue' => ['sky', __('Non-urgent')],
    ];
    [$color, $label] = $map[$category] ?? ['zinc', ucfirst((string) $category)];
@endphp

@if ($category)
    <flux:badge size="sm" :color="$color">{{ $label }}</flux:badge>
@endif
