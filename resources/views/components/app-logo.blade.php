@props([
    'sidebar' => false,
])

@php
    // Institution branding wins inside a hospital; otherwise the system mark.
    $brandTenant = app(\App\Services\Tenancy\TenantContext::class)->tenant();
    $brandName = $brandTenant?->displayName() ?? config('app.name', 'Laravel');
    $logoUrl = $brandTenant?->logoUrl();
@endphp

@if($sidebar)
    <flux:sidebar.brand :name="$brandName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="size-9 rounded-lg object-contain" />
            @else
                <x-app-logo-icon class="size-9 drop-shadow-sm" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="size-9 rounded-lg object-contain" />
            @else
                <x-app-logo-icon class="size-9 drop-shadow-sm" />
            @endif
        </x-slot>
    </flux:brand>
@endif
