{{--
    Institution letterhead for printed documents. Inline styles only, so it
    renders the same inside app pages and standalone print views.
--}}
@props([
    'tenant',
    'facility' => null,
    'title' => null,
    'number' => null,
    'date' => null,
    'primary' => null,
    'secondary' => null,
    'logoPreview' => null,
    'compact' => false,
])

@php
    $primary = $primary ?: $tenant?->brandColor('primary') ?? '#4E7CFF';
    $secondary = $secondary ?: $tenant?->brandColor('secondary') ?? '#7033FF';
    $showLogo = (bool) ($tenant?->setting('documents.show_logo', true) ?? true);
    $logo = $logoPreview ?: ($showLogo ? $tenant?->logoUrl() : null);
    $contacts = collect([
        $tenant?->phone ? 'Tel: '.$tenant->phone : null,
        $tenant?->phone_alt,
        $tenant?->email,
        $tenant?->website ? preg_replace('#^https?://#', '', $tenant->website) : null,
    ])->filter()->implode(' · ');
    $numbers = collect([
        $tenant?->license_number ? 'Licence: '.$tenant->license_number : null,
        $tenant?->kra_pin ? 'PIN: '.$tenant->kra_pin : null,
        $facility?->mfl_code ? 'MFL: '.$facility->mfl_code : null,
        $facility?->fr_code ? 'FR: '.$facility->fr_code : null,
    ])->filter()->implode(' · ');
@endphp

<div {{ $attributes->merge(['style' => 'font-family: inherit; color: #33394C;']) }}>
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: {{ $compact ? '8px' : '14px' }}; {{ $compact ? 'flex-direction: column; text-align: center;' : '' }}">
        @if ($logo)
            <img src="{{ $logo }}" alt="" style="height: {{ $compact ? '44px' : '58px' }}; width: auto; max-width: 120px; object-fit: contain;">
        @elseif (! $compact)
            <x-app-logo-icon style="height: 52px; width: 52px; flex-shrink: 0;" />
        @endif
        <div style="flex: 1; min-width: 180px;">
            <div style="font-size: {{ $compact ? '15px' : '18px' }}; font-weight: 700; color: {{ $primary }}; line-height: 1.2;">{{ $tenant?->name }}</div>
            @if ($tenant?->motto)<div style="font-size: 11px; font-style: italic; color: {{ $secondary }};">{{ $tenant->motto }}</div>@endif
            <div style="font-size: 10.5px; color: #6E768A; margin-top: 2px;">
                @if ($facility && $facility->name !== $tenant?->name){{ $facility->name }} · @endif{{ $tenant?->postalAddress() }}
            </div>
            @if ($contacts)<div style="font-size: 10.5px; color: #6E768A;">{{ $contacts }}</div>@endif
            @if ($numbers)<div style="font-size: 10px; color: #9CA4B8;">{{ $numbers }}</div>@endif
        </div>
        @if (($title || $number) && ! $compact)
            <div style="text-align: right; margin-inline-start: auto;">
                @if ($title)<div style="font-size: 13px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;">{{ $title }}</div>@endif
                @if ($number)<div style="font-family: ui-monospace, monospace; font-size: 11px;">{{ $number }}</div>@endif
                <div style="font-size: 10.5px; color: #6E768A;">{{ ($date ?? now())->format('d M Y') }}</div>
            </div>
        @endif
    </div>
    <div style="height: 4px; margin-top: 10px; border-radius: 4px; background: linear-gradient(90deg, {{ $primary }}, {{ $secondary }});"></div>
    @if ($compact && ($title || $number))
        <div style="text-align: center; margin-top: 8px;">
            @if ($title)<div style="font-size: 13px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;">{{ $title }}</div>@endif
            @if ($number)<div style="font-family: ui-monospace, monospace; font-size: 11px;">{{ $number }}</div>@endif
        </div>
    @endif
</div>
