@props(['patient', 'encounter' => null])

@php
    $allergies = $patient->relationLoaded('allergies') ? $patient->allergies : $patient->allergies()->get();
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-900']) }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <flux:avatar :name="$patient->full_name" size="lg" />
            <div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('patients.show', $patient) }}" wire:navigate class="text-lg font-semibold hover:underline">{{ $patient->full_name }}</a>
                    @if ($encounter?->triage_category)
                        <x-triage-badge :category="$encounter->triage_category" />
                    @endif
                </div>
                <div class="text-sm text-neutral-500">
                    <span class="font-mono">{{ $patient->mrn }}</span>
                    &middot; {{ ucfirst($patient->sex) }}
                    &middot; {{ $patient->age !== null ? $patient->age.' '.__('yrs') : __('Age unknown') }}
                    @if ($patient->phone) &middot; {{ $patient->phone }} @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($encounter)
                <flux:badge size="sm">{{ $encounter->encounter_number }}</flux:badge>
                <flux:badge size="sm" :color="\App\Support\EncounterStage::color($encounter->stage)">{{ \App\Support\EncounterStage::label($encounter->stage) }}</flux:badge>
                <flux:badge size="sm" color="zinc">{{ ucfirst($encounter->payer_type) }}</flux:badge>
            @endif
        </div>
    </div>

    @if ($allergies->isNotEmpty())
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <flux:badge color="red" icon="exclamation-triangle" size="sm">{{ __('Allergies') }}</flux:badge>
            @foreach ($allergies as $allergy)
                <span class="text-red-600 dark:text-red-400">{{ $allergy->allergen }}@if ($allergy->reaction) ({{ $allergy->reaction }})@endif{{ ! $loop->last ? ',' : '' }}</span>
            @endforeach
        </div>
    @endif
</div>
