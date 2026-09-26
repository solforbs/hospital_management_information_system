<?php

use App\Models\Patient;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Patient')] class extends Component {
    public Patient $patient;

    public function mount(Patient $patient): void
    {
        abort_unless(Auth::user()->can('patients.view'), 403);

        $this->patient = $patient;
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-start justify-between">
        <div>
            <flux:heading level="1">{{ $patient->full_name }}</flux:heading>
            <flux:text class="mt-1 font-mono text-sm">{{ $patient->mrn }}</flux:text>
        </div>

        <flux:button variant="ghost" :href="route('patients.index')" wire:navigate>
            {{ __('Back to patients') }}
        </flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:text class="text-xs uppercase text-neutral-500">{{ __('Sex') }}</flux:text>
            <flux:heading level="3">{{ ucfirst($patient->sex) }}</flux:heading>
        </div>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:text class="text-xs uppercase text-neutral-500">{{ __('Age') }}</flux:text>
            <flux:heading level="3">
                {{ $patient->age !== null ? $patient->age.' '.__('years') : __('Unknown') }}
                @if ($patient->date_of_birth_estimated)
                    <flux:badge size="sm">{{ __('Estimated') }}</flux:badge>
                @endif
            </flux:heading>
        </div>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:text class="text-xs uppercase text-neutral-500">{{ __('Category') }}</flux:text>
            <flux:heading level="3">{{ ucfirst($patient->category) }}</flux:heading>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Contact') }}</flux:heading>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Phone') }}</dt><dd>{{ $patient->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Email') }}</dt><dd>{{ $patient->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Address') }}</dt><dd>{{ $patient->address ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('County') }}</dt><dd>{{ $patient->county ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('National ID') }}</dt><dd>{{ $patient->national_id ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Next of kin') }}</flux:heading>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Name') }}</dt><dd>{{ $patient->next_of_kin_name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Phone') }}</dt><dd>{{ $patient->next_of_kin_phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Relationship') }}</dt><dd>{{ $patient->next_of_kin_relationship ?? '—' }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="rounded-xl border border-dashed border-neutral-300 p-6 text-sm text-neutral-500 dark:border-neutral-700">
        {{ __('Appointments, encounters, and clinical history will appear here once those modules are built.') }}
    </div>
</div>
