@php
    // Each page takes the colour of its sidebar section (see ModuleRegistry::GROUP_COLORS).
    [$accent, $accent2] = \App\Support\ModuleRegistry::colorsForRoute(request()->route()?->getName());
@endphp
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="hms-page" style="--page-accent: {{ $accent }}; --page-accent-2: {{ $accent2 }};">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
