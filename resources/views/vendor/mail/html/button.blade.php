@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
    'brandColor' => null,
])
@php
    // Brand colour for the main call to action; success/error keep their meaning.
    $bg = $color === 'primary' && $brandColor ? $brandColor : null;
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" @if ($bg) style="background-color: {{ $bg }}; border-top: 12px solid {{ $bg }}; border-right: 28px solid {{ $bg }}; border-bottom: 12px solid {{ $bg }}; border-left: 28px solid {{ $bg }};" @endif>{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
