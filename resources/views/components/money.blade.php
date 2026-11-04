@props(['amount'])
<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ config('hms.currency') }} {{ number_format((float) $amount, 2) }}</span>
