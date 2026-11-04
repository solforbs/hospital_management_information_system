<?php

use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\Audit\AuditLogger;
use App\Support\EncounterStage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Patient')] class extends Component {
    public Patient $patient;

    public string $tab = 'visits';

    public ?int $insurance_provider_id = null;

    public string $member_number = '';

    public string $scheme_name = '';

    public ?string $valid_until = null;

    public function mount(Patient $patient, AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('patients.view'), 403);

        $this->patient = $patient;

        // Access to a patient record is itself an auditable event.
        $audit->log('patient.viewed', $patient);
    }

    #[Computed]
    public function encounters()
    {
        return $this->patient->encounters()->with(['doctor', 'diagnoses'])->latest('started_at')->limit(50)->get();
    }

    #[Computed]
    public function appointments()
    {
        return $this->patient->appointments()->with('doctor')->latest('scheduled_at')->limit(20)->get();
    }

    #[Computed]
    public function insurances()
    {
        return $this->patient->insurances()->with('provider')->latest()->get();
    }

    #[Computed]
    public function providers()
    {
        return InsuranceProvider::where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function vitals()
    {
        return $this->patient->vitalSigns()->latest('recorded_at')->limit(10)->get();
    }

    #[Computed]
    public function invoices()
    {
        return Auth::user()->can('billing.invoices.view')
            ? $this->patient->invoices()->latest()->limit(20)->get()
            : collect();
    }

    public function addInsurance(): void
    {
        abort_unless(Auth::user()->can('patients.update') || Auth::user()->can('insurance.manage'), 403);

        $data = $this->validate([
            'insurance_provider_id' => ['required', 'exists:insurance_providers,id'],
            'member_number' => ['required', 'string', 'max:50'],
            'scheme_name' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date'],
        ]);

        // exists rule is not tenant-aware; re-resolve through the scoped model.
        InsuranceProvider::findOrFail($data['insurance_provider_id']);

        PatientInsurance::create($data + ['patient_id' => $this->patient->id, 'active' => true]);

        $this->reset(['insurance_provider_id', 'member_number', 'scheme_name', 'valid_until']);
        unset($this->insurances);
        Flux::toast(variant: 'success', text: __('Insurance cover added.'));
    }

    public function toggleInsurance(int $id): void
    {
        abort_unless(Auth::user()->can('patients.update') || Auth::user()->can('insurance.manage'), 403);

        $cover = $this->patient->insurances()->findOrFail($id);
        $cover->update(['active' => ! $cover->active]);
        unset($this->insurances);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <flux:heading level="1">{{ __('Patient record') }}</flux:heading>
        <div class="flex gap-2">
            @can('reception.checkin')
                <flux:button icon="arrow-right-end-on-rectangle" :href="route('reception.index', ['patient' => $patient->id])" wire:navigate>{{ __('Check in') }}</flux:button>
            @endcan
            @can('appointments.manage')
                <flux:button icon="calendar-days" :href="route('appointments.index', ['patient' => $patient->id])" wire:navigate>{{ __('Book appointment') }}</flux:button>
            @endcan
            @can('patients.update')
                <flux:button icon="pencil-square" :href="route('patients.edit', $patient)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endcan
            <flux:button variant="ghost" :href="route('patients.index')" wire:navigate>{{ __('Back') }}</flux:button>
        </div>
    </div>

    <x-patient-banner :patient="$patient" :encounter="$patient->activeEncounter()" />

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Contact') }}</flux:heading>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Phone') }}</dt><dd>{{ $patient->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Email') }}</dt><dd>{{ $patient->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Address') }}</dt><dd>{{ $patient->address ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('County') }}</dt><dd>{{ $patient->county ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('National ID') }}</dt><dd>{{ $patient->national_id ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Date of birth') }}</dt><dd>{{ $patient->date_of_birth?->format('d M Y') ?? '—' }} @if ($patient->date_of_birth_estimated) ({{ __('est.') }}) @endif</dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Next of kin') }}</flux:heading>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Name') }}</dt><dd>{{ $patient->next_of_kin_name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Phone') }}</dt><dd>{{ $patient->next_of_kin_phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">{{ __('Relationship') }}</dt><dd>{{ $patient->next_of_kin_relationship ?? '—' }}</dd></div>
            </dl>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Latest vitals') }}</flux:heading>
            @if ($v = $this->vitals->first())
                <dl class="mt-3 grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-neutral-500">{{ __('BP') }}</dt><dd>{{ $v->bloodPressure() ?? '—' }}</dd>
                    <dt class="text-neutral-500">{{ __('Pulse') }}</dt><dd>{{ $v->pulse ?? '—' }}</dd>
                    <dt class="text-neutral-500">{{ __('Temp') }}</dt><dd>{{ $v->temperature ?? '—' }}</dd>
                    <dt class="text-neutral-500">{{ __('SpO₂') }}</dt><dd>{{ $v->spo2 ?? '—' }}</dd>
                    <dt class="text-neutral-500">{{ __('Weight') }}</dt><dd>{{ $v->weight_kg ?? '—' }}</dd>
                    <dt class="text-neutral-500">{{ __('Recorded') }}</dt><dd>{{ $v->recorded_at->format('d M H:i') }}</dd>
                </dl>
            @else
                <flux:text class="mt-3">{{ __('No vitals recorded yet.') }}</flux:text>
            @endif
        </div>
    </div>

    <div class="flex gap-2 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['visits' => __('Visits'), 'appointments' => __('Appointments'), 'insurance' => __('Insurance'), 'billing' => __('Billing')] as $key => $label)
            @if ($key !== 'billing' || auth()->user()->can('billing.invoices.view'))
                <button type="button" wire:click="$set('tab', '{{ $key }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $key ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $label }}</button>
            @endif
        @endforeach
    </div>

    @if ($tab === 'visits')
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-4 py-2">{{ __('Visit') }}</th><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('Type') }}</th><th class="px-4 py-2">{{ __('Doctor') }}</th><th class="px-4 py-2">{{ __('Diagnosis') }}</th><th class="px-4 py-2">{{ __('Status') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->encounters as $e)
                        <tr wire:key="enc-{{ $e->id }}">
                            <td class="px-4 py-2 font-mono text-xs">{{ $e->encounter_number }}</td>
                            <td class="px-4 py-2">{{ $e->started_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2">{{ ucfirst($e->type) }}</td>
                            <td class="px-4 py-2">{{ $e->doctor?->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $e->diagnoses->pluck('description')->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-2"><flux:badge size="sm" :color="EncounterStage::color($e->stage)">{{ EncounterStage::label($e->stage) }}</flux:badge></td>
                            <td class="px-4 py-2 text-right">
                                @can('encounters.view')
                                    <flux:button size="sm" variant="ghost" :href="route('consultation.show', $e)" wire:navigate>{{ __('Open') }}</flux:button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-neutral-500">{{ __('No visits yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'appointments')
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-4 py-2">{{ __('Number') }}</th><th class="px-4 py-2">{{ __('When') }}</th><th class="px-4 py-2">{{ __('Doctor') }}</th><th class="px-4 py-2">{{ __('Reason') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->appointments as $a)
                        <tr wire:key="appt-{{ $a->id }}">
                            <td class="px-4 py-2 font-mono text-xs">{{ $a->appointment_number }}</td>
                            <td class="px-4 py-2">{{ $a->scheduled_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2">{{ $a->doctor?->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $a->reason ?? '—' }}</td>
                            <td class="px-4 py-2"><flux:badge size="sm">{{ str_replace('_', ' ', $a->status) }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-neutral-500">{{ __('No appointments.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'insurance')
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="space-y-2">
                @forelse ($this->insurances as $cover)
                    <div wire:key="cover-{{ $cover->id }}" class="flex items-center justify-between rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div>
                            <div class="font-medium">{{ $cover->provider->name }} @if ($cover->scheme_name) — {{ $cover->scheme_name }} @endif</div>
                            <div class="text-neutral-500">{{ __('Member') }}: {{ $cover->member_number }} @if ($cover->valid_until) &middot; {{ __('valid until') }} {{ $cover->valid_until->format('d M Y') }} @endif</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$cover->isValid() ? 'lime' : 'zinc'">{{ $cover->isValid() ? __('Active') : __('Inactive') }}</flux:badge>
                            @canany(['patients.update', 'insurance.manage'])
                                <flux:button size="xs" variant="ghost" wire:click="toggleInsurance({{ $cover->id }})">{{ $cover->active ? __('Deactivate') : __('Activate') }}</flux:button>
                            @endcanany
                        </div>
                    </div>
                @empty
                    <flux:text>{{ __('No insurance cover on file — patient is cash-paying.') }}</flux:text>
                @endforelse
            </div>
            @canany(['patients.update', 'insurance.manage'])
                <form wire:submit="addInsurance" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Add cover') }}</flux:heading>
                    <flux:select wire:model="insurance_provider_id" :label="__('Provider')">
                        <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                        @foreach ($this->providers as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input wire:model="member_number" :label="__('Member number')" />
                        <flux:input wire:model="scheme_name" :label="__('Scheme')" />
                        <flux:input wire:model="valid_until" type="date" :label="__('Valid until')" />
                    </div>
                    <div><flux:button type="submit" variant="primary" size="sm">{{ __('Add cover') }}</flux:button></div>
                </form>
            @endcanany
        </div>
    @elseif ($tab === 'billing')
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-4 py-2">{{ __('Invoice') }}</th><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2 text-right">{{ __('Total') }}</th><th class="px-4 py-2 text-right">{{ __('Balance') }}</th><th class="px-4 py-2">{{ __('Status') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->invoices as $inv)
                        <tr wire:key="inv-{{ $inv->id }}">
                            <td class="px-4 py-2 font-mono text-xs">{{ $inv->invoice_number }}</td>
                            <td class="px-4 py-2">{{ $inv->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-2 text-right"><x-money :amount="$inv->total" /></td>
                            <td class="px-4 py-2 text-right"><x-money :amount="$inv->balance" /></td>
                            <td class="px-4 py-2"><flux:badge size="sm">{{ str_replace('_', ' ', $inv->status) }}</flux:badge></td>
                            <td class="px-4 py-2 text-right"><flux:button size="sm" variant="ghost" :href="route('billing.invoice', $inv)" wire:navigate>{{ __('Open') }}</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-neutral-500">{{ __('No invoices.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
