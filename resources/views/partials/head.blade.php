<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    @php
        $titleBrand = app(\App\Services\Tenancy\TenantContext::class)->tenant()?->displayName() ?? config('app.name', 'Laravel');
    @endphp
    {{ filled($title ?? null) ? $title.' - '.$titleBrand : $titleBrand }}
</title>

@php
    $brandTenant = app(\App\Services\Tenancy\TenantContext::class)->tenant();
@endphp
{{-- Signed in: the institution's own logo is the tab / home-screen icon. --}}
@if ($brandTenant?->logoUrl())
    <link rel="icon" href="{{ $brandTenant->logoUrl() }}" data-brand="institution">
    <link rel="apple-touch-icon" href="{{ $brandTenant->logoUrl() }}">
@else
    <link rel="icon" href="/favicon.svg" type="image/svg+xml" data-brand="system">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    // The HMS theme is designed light-first: default to light until the user
    // picks an appearance under Settings → Appearance (which is then respected).
    if (! window.localStorage.getItem('hms.appearance-initialised')) {
        window.localStorage.setItem('hms.appearance-initialised', '1');
        window.localStorage.setItem('flux.appearance', 'light');
    }
</script>
@fluxAppearance
