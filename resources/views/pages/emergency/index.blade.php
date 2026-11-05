<?php

use App\Models\Encounter;
use App\Models\Patient;
use App\Services\Clinical\EncounterService;
use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Emergency')] class extends Component {
    public string $mode = 'existing';

    public string $patientSearch = '';

    public ?int $patient_id = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $sex = 'unknown';

    public ?int $estimated_age = null;

    public string $phone = '';

    public string $triage_category = 'orange';

    public string $chief_complaint = '';

    public string $arrival_mode = 'walk_in';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('emergency.manage'), 403);
    }

    #[Computed]
    public function patientMatches()
    {
        $t = trim($this->patientSearch);
        if (strlen($t) < 2) {
            return collect();
        }

        return Patient::where(fn ($q) => $q->where('mrn', 'like', "%$t%")->orWhere('first_name', 'like', "%$t%")->orWhere('last_name', 'like', "%$t%")->orWhere('phone', 'like', "%$t%"))->limit(6)->get();
    }

    #[Computed]
    public function board()
    {
        return Encounter::with(['patient', 'doctor', 'latestVitals'])
            ->where('type', 'emergency')
            ->where('status', 'open')
            ->orderByRaw("case triage_category when 'red' then 0 when 'orange' then 1 when 'yellow' then 2 when 'green' then 3 else 4 end")
            ->orderBy('started_at')
            ->get();
    }

    public function admit(EncounterService $encounters, PatientRegistrationService $registration): void
    {
        abort_unless(Auth::user()->can('emergency.manage'), 403);

        $this->validate([
            'triage_category' => ['required', 'in:red,orange,yellow,green,blue'],
            'chief_complaint' => ['required', 'string', 'max:255'],
            'arrival_mode' => ['required', 'in:walk_in,ambulance,police,referral'],
        ]);

        if ($this->mode === 'existing') {
            $this->validate(['patient_id' => ['required', 'integer']]);
            $patient = Patient::findOrFail($this->patient_id);
        } else {
            $this->validate([
                'first_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['nullable', 'string', 'max:100'],
                'sex' => ['required', 'in:male,female,unknown'],
                'estimated_age' => ['nullable', 'integer', 'between:0,120'],
                'phone' => ['nullable', 'string', 'max:30'],
            ]);

            // Unidentified patients get a placeholder name; the record is
            // corrected (and merged if needed) once identity is confirmed.
            $context = app(TenantContext::class);
            $patient = $registration->register($context->tenant(), [
                'first_name' => $this->first_name ?: __('Unknown'),
                'last_name' => $this->last_name ?: strtoupper(substr(md5(uniqid()), 0, 6)),
                'sex' => $this->sex,
                'date_of_birth' => $this->estimated_age !== null ? now()->subYears($this->estimated_age)->startOfYear()->toDateString() : null,
                'date_of_birth_estimated' => $this->estimated_age !== null,
                'phone' => $this->phone ?: null,
                'category' => ($this->first_name && $this->last_name) ? 'general' : 'unidentified',
                'registered_by' => Auth::id(),
            ], $context->facility());
        }

        $encounter = $encounters->checkIn($patient, [
            'type' => 'emergency',
            'stage' => EncounterStage::WAITING_DOCTOR,
            'triage_category' => $this->triage_category,
            'priority' => 'emergency',
            'chief_complaint' => $this->chief_complaint.' ['.str_replace('_', ' ', $this->arrival_mode).']',
        ]);

        Flux::toast(variant: 'success', text: __('Emergency visit :n opened for :name.', ['n' => $encounter->encounter_number, 'name' => $patient->full_name]));
        $this->reset(['patient_id', 'patientSearch', 'first_name', 'last_name', 'sex', 'estimated_age', 'phone', 'chief_complaint', 'triage_category', 'arrival_mode']);
        unset($this->board);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="queue,triage">
    <flux:heading level="1">{{ __('Emergency department') }}</flux:heading>

    <div class="grid gap-6 xl:grid-cols-3">
        <form wire:submit="admit" class="grid content-start gap-3 rounded-xl border border-red-300 p-5 dark:border-red-800">
            <flux:heading level="3">{{ __('Rapid registration') }}</flux:heading>
            <div class="flex gap-2">
                <flux:button size="sm" :variant="$mode === 'existing' ? 'primary' : 'ghost'" wire:click="$set('mode', 'existing')">{{ __('Known patient') }}</flux:button>
                <flux:button size="sm" :variant="$mode === 'new' ? 'primary' : 'ghost'" wire:click="$set('mode', 'new')">{{ __('New / unidentified') }}</flux:button>
            </div>

            @if ($mode === 'existing')
                @if ($patient_id && ($p = \App\Models\Patient::find($patient_id)))
                    <div class="flex items-center justify-between rounded-lg bg-neutral-100 px-3 py-2 text-sm dark:bg-neutral-800">
                        <span>{{ $p->mrn }} — {{ $p->full_name }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="$set('patient_id', null)">{{ __('Change') }}</flux:button>
                    </div>
                @else
                    <flux:input wire:model.live.debounce.300ms="patientSearch" icon="magnifying-glass" :placeholder="__('Search patient')" />
                    @error('patient_id') <flux:text class="text-sm text-red-500">{{ __('Select a patient.') }}</flux:text> @enderror
                    @foreach ($this->patientMatches as $m)
                        <button type="button" wire:key="em-{{ $m->id }}" wire:click="$set('patient_id', {{ $m->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">{{ $m->mrn }} — {{ $m->full_name }}</button>
                    @endforeach
                @endif
            @else
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="first_name" :label="__('First name')" :placeholder="__('If known')" />
                    <flux:input wire:model="last_name" :label="__('Last name')" :placeholder="__('If known')" />
                    <flux:select wire:model="sex" :label="__('Sex')">
                        <flux:select.option value="unknown">{{ __('Unknown') }}</flux:select.option>
                        <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                        <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="estimated_age" type="number" :label="__('Est. age')" />
                </div>
                <flux:input wire:model="phone" :label="__('Phone / escort contact')" />
            @endif

            <flux:select wire:model="triage_category" :label="__('Triage')">
                <flux:select.option value="red">{{ __('Red — immediate') }}</flux:select.option>
                <flux:select.option value="orange">{{ __('Orange — very urgent') }}</flux:select.option>
                <flux:select.option value="yellow">{{ __('Yellow — urgent') }}</flux:select.option>
                <flux:select.option value="green">{{ __('Green — standard') }}</flux:select.option>
                <flux:select.option value="blue">{{ __('Blue — non-urgent') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model="arrival_mode" :label="__('Arrival')">
                <flux:select.option value="walk_in">{{ __('Walk-in') }}</flux:select.option>
                <flux:select.option value="ambulance">{{ __('Ambulance') }}</flux:select.option>
                <flux:select.option value="police">{{ __('Police') }}</flux:select.option>
                <flux:select.option value="referral">{{ __('Referral') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="chief_complaint" :label="__('Presenting complaint')" required />
            <flux:button type="submit" variant="danger" wire:loading.attr="disabled">{{ __('Open emergency visit') }}</flux:button>
        </form>

        <div class="xl:col-span-2">
            <flux:heading level="3" class="mb-3">{{ __('ED board') }} ({{ $this->board->count() }})</flux:heading>
            <div class="grid gap-3 md:grid-cols-2">
                @forelse ($this->board as $e)
                    @php $border = ['red' => 'border-red-500', 'orange' => 'border-orange-500', 'yellow' => 'border-yellow-500', 'green' => 'border-lime-500'][$e->triage_category] ?? 'border-neutral-300'; @endphp
                    <div wire:key="ed-{{ $e->id }}" class="rounded-xl border-l-4 {{ $border }} border-y border-r border-y-neutral-200 border-r-neutral-200 p-4 dark:border-y-neutral-700 dark:border-r-neutral-700">
                        <div class="flex items-center justify-between">
                            <div class="font-medium">{{ $e->patient->full_name }}</div>
                            <x-triage-badge :category="$e->triage_category" />
                        </div>
                        <div class="text-xs text-neutral-500">{{ $e->patient->mrn }} &middot; {{ ucfirst($e->patient->sex) }} {{ $e->patient->age }} &middot; {{ __('arrived') }} {{ $e->started_at->format('H:i') }} ({{ $e->started_at->diffForHumans() }})</div>
                        <div class="mt-2 text-sm">{{ $e->chief_complaint }}</div>
                        @if ($v = $e->latestVitals)
                            <div class="mt-1 text-xs text-neutral-500">BP {{ $v->bloodPressure() ?? '—' }} · HR {{ $v->pulse ?? '—' }} · SpO₂ {{ $v->spo2 ?? '—' }} · T {{ $v->temperature ?? '—' }}</div>
                        @endif
                        <div class="mt-3 flex items-center justify-between">
                            <flux:badge size="sm" :color="EncounterStage::color($e->stage)">{{ EncounterStage::label($e->stage) }}</flux:badge>
                            <span class="text-xs">{{ $e->doctor?->name ?? __('Unassigned') }}</span>
                            @can('encounters.create')
                                <flux:button size="xs" variant="primary" :href="route('consultation.show', $e)" wire:navigate>{{ __('Attend') }}</flux:button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <flux:text>{{ __('No patients in the emergency department.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>
</div>
