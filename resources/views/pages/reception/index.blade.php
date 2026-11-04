<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\EncounterService;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reception')] class extends Component {
    #[Url(as: 'patient')]
    public ?int $patient_id = null;

    public string $patientSearch = '';

    public ?int $department_id = null;

    public ?int $doctor_id = null;

    public string $priority = 'normal';

    public string $payer_type = 'cash';

    public string $chief_complaint = '';

    public bool $skipTriage = false;

    public string $stageFilter = 'active';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('reception.checkin'), 403);
    }

    #[Computed]
    public function selectedPatient()
    {
        return $this->patient_id ? Patient::with('insurances.provider')->find($this->patient_id) : null;
    }

    #[Computed]
    public function patientMatches()
    {
        $t = trim($this->patientSearch);
        if (strlen($t) < 2) {
            return collect();
        }

        return Patient::where(fn ($q) => $q->where('mrn', 'like', "%$t%")->orWhere('first_name', 'like', "%$t%")->orWhere('last_name', 'like', "%$t%")->orWhere('phone', 'like', "%$t%")->orWhere('national_id', 'like', "%$t%"))
            ->limit(8)->get();
    }

    #[Computed]
    public function departments()
    {
        return Department::where('active', true)->where('type', 'clinical')->orderBy('name')->get();
    }

    #[Computed]
    public function doctors()
    {
        return app(TenantContext::class)->tenant()->users()->wherePivot('status', 'active')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->can('encounters.create'))->values();
    }

    #[Computed]
    public function todaysAppointments()
    {
        return Appointment::with(['patient', 'doctor'])
            ->whereDate('scheduled_at', today())
            ->whereIn('status', ['booked', 'confirmed'])
            ->orderBy('scheduled_at')
            ->get();
    }

    #[Computed]
    public function queue()
    {
        return Encounter::with(['patient', 'doctor', 'department'])
            ->when($this->stageFilter === 'active', fn ($q) => $q->where('status', 'open'))
            ->when($this->stageFilter === 'today', fn ($q) => $q->whereDate('started_at', today()))
            ->when(! in_array($this->stageFilter, ['active', 'today']), fn ($q) => $q->where('stage', $this->stageFilter))
            ->orderByRaw("case priority when 'emergency' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('started_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function stageCounts()
    {
        return Encounter::where('status', 'open')->selectRaw('stage, count(*) as c')->groupBy('stage')->pluck('c', 'stage');
    }

    public function selectPatient(int $id): void
    {
        $this->patient_id = Patient::findOrFail($id)->id;
        $this->patientSearch = '';
        $this->payer_type = $this->selectedPatient?->insurances->contains(fn ($i) => $i->isValid()) ? 'insurance' : 'cash';
    }

    public function checkIn(EncounterService $encounters): void
    {
        abort_unless(Auth::user()->can('reception.checkin'), 403);

        $data = $this->validate([
            'patient_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'doctor_id' => ['nullable', 'integer'],
            'priority' => ['required', 'in:normal,urgent,emergency'],
            'payer_type' => ['required', 'in:cash,insurance,corporate,waiver'],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
        ]);

        $patient = Patient::findOrFail($data['patient_id']);

        if ($data['payer_type'] === 'insurance' && ! $patient->insurances()->get()->contains(fn ($i) => $i->isValid())) {
            $this->addError('payer_type', __('Patient has no valid insurance cover on file. Add it on the patient record first.'));

            return;
        }

        $encounter = $encounters->checkIn($patient, [
            'department_id' => $data['department_id'] ? Department::findOrFail($data['department_id'])->id : null,
            'attending_doctor_id' => $data['doctor_id'] && $this->doctors->contains('id', $data['doctor_id']) ? $data['doctor_id'] : null,
            'priority' => $data['priority'],
            'payer_type' => $data['payer_type'],
            'chief_complaint' => $data['chief_complaint'] ?: null,
            'stage' => $this->skipTriage ? EncounterStage::WAITING_DOCTOR : EncounterStage::WAITING_TRIAGE,
        ]);

        Flux::toast(variant: 'success', text: __(':name checked in — queue no. :n (:visit)', ['name' => $patient->full_name, 'n' => $encounter->queue_number, 'visit' => $encounter->encounter_number]));

        $this->reset(['patient_id', 'department_id', 'doctor_id', 'priority', 'payer_type', 'chief_complaint', 'skipTriage']);
        unset($this->queue, $this->stageCounts);
    }

    public function checkInAppointment(int $id, EncounterService $encounters): void
    {
        abort_unless(Auth::user()->can('reception.checkin'), 403);

        $appointment = Appointment::findOrFail($id);
        $encounter = $encounters->checkIn($appointment->patient, [], $appointment);

        Flux::toast(variant: 'success', text: __('Checked in — queue no. :n', ['n' => $encounter->queue_number]));
        unset($this->queue, $this->todaysAppointments, $this->stageCounts);
    }

    public function cancelVisit(int $id, EncounterService $encounters): void
    {
        abort_unless(Auth::user()->can('reception.checkin'), 403);

        $encounters->cancel(Encounter::findOrFail($id), __('Cancelled at reception'));
        unset($this->queue, $this->stageCounts);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.30s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Reception & patient flow') }}</flux:heading>
        @can('patients.create')
            <flux:button icon="user-plus" :href="route('patients.create')" wire:navigate>{{ __('Register new patient') }}</flux:button>
        @endcan
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
        @foreach ([EncounterStage::WAITING_TRIAGE, EncounterStage::WAITING_DOCTOR, EncounterStage::IN_CONSULTATION, EncounterStage::AWAITING_RESULTS, EncounterStage::WAITING_PHARMACY, EncounterStage::WAITING_BILLING, EncounterStage::ADMITTED] as $stage)
            <button type="button" wire:click="$set('stageFilter', '{{ $stage }}')" class="rounded-xl border p-3 text-left {{ $stageFilter === $stage ? 'border-indigo-500' : 'border-neutral-200 dark:border-neutral-700' }}">
                <div class="text-2xl font-semibold">{{ $this->stageCounts[$stage] ?? 0 }}</div>
                <div class="text-xs text-neutral-500">{{ EncounterStage::label($stage) }}</div>
            </button>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-1">
            <form wire:submit="checkIn" class="grid gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading level="3">{{ __('Walk-in check-in') }}</flux:heading>

                @if ($this->selectedPatient)
                    <div class="flex items-center justify-between rounded-lg bg-neutral-100 px-3 py-2 text-sm dark:bg-neutral-800">
                        <span><span class="font-mono">{{ $this->selectedPatient->mrn }}</span> — {{ $this->selectedPatient->full_name }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="$set('patient_id', null)">{{ __('Change') }}</flux:button>
                    </div>
                @else
                    <div>
                        <flux:input wire:model.live.debounce.300ms="patientSearch" icon="magnifying-glass" :placeholder="__('Search MRN, name, phone, ID')" />
                        @error('patient_id') <flux:text class="text-sm text-red-500">{{ __('Select a patient.') }}</flux:text> @enderror
                        <div class="mt-1 divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($this->patientMatches as $p)
                                <button type="button" wire:key="m-{{ $p->id }}" wire:click="selectPatient({{ $p->id }})" class="block w-full px-2 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                    <span class="font-mono">{{ $p->mrn }}</span> — {{ $p->full_name }} <span class="text-neutral-500">{{ $p->phone }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <flux:select wire:model="department_id" :label="__('Clinic')">
                    <flux:select.option value="">{{ __('General outpatient') }}</flux:select.option>
                    @foreach ($this->departments as $dep)
                        <flux:select.option value="{{ $dep->id }}">{{ $dep->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="doctor_id" :label="__('Doctor')">
                    <flux:select.option value="">{{ __('Next available') }}</flux:select.option>
                    @foreach ($this->doctors as $d)
                        <flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model="priority" :label="__('Priority')">
                        <flux:select.option value="normal">{{ __('Normal') }}</flux:select.option>
                        <flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option>
                        <flux:select.option value="emergency">{{ __('Emergency') }}</flux:select.option>
                    </flux:select>
                    <flux:select wire:model="payer_type" :label="__('Payer')">
                        <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
                        <flux:select.option value="insurance">{{ __('Insurance') }}</flux:select.option>
                        <flux:select.option value="corporate">{{ __('Corporate') }}</flux:select.option>
                        <flux:select.option value="waiver">{{ __('Waiver') }}</flux:select.option>
                    </flux:select>
                </div>
                <flux:input wire:model="chief_complaint" :label="__('Reason for visit')" />
                <flux:checkbox wire:model="skipTriage" :label="__('Send straight to doctor (skip triage)')" />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Check in') }}</flux:button>
            </form>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading level="3">{{ __("Today's booked appointments") }}</flux:heading>
                <div class="mt-3 space-y-2">
                    @forelse ($this->todaysAppointments as $a)
                        <div wire:key="ta-{{ $a->id }}" class="flex items-center justify-between text-sm">
                            <div>
                                <span class="font-medium">{{ $a->scheduled_at->format('H:i') }}</span> {{ $a->patient->full_name }}
                                <div class="text-xs text-neutral-500">{{ $a->doctor?->name ?? __('Any doctor') }}</div>
                            </div>
                            <flux:button size="xs" wire:click="checkInAppointment({{ $a->id }})">{{ __('Check in') }}</flux:button>
                        </div>
                    @empty
                        <flux:text>{{ __('No pending appointments today.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="xl:col-span-2">
            <div class="mb-3 flex items-center gap-2">
                <flux:heading level="3">{{ __('Patient queue') }}</flux:heading>
                <flux:select wire:model.live="stageFilter" size="sm" class="max-w-56">
                    <flux:select.option value="active">{{ __('All open visits') }}</flux:select.option>
                    <flux:select.option value="today">{{ __("All of today's visits") }}</flux:select.option>
                    @foreach (EncounterStage::labels() as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-left text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr><th class="px-3 py-2">#</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Stage') }}</th><th class="px-3 py-2">{{ __('Doctor') }}</th><th class="px-3 py-2">{{ __('Waiting') }}</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse ($this->queue as $e)
                            <tr wire:key="q-{{ $e->id }}" class="{{ $e->priority === 'emergency' ? 'bg-red-50 dark:bg-red-950/40' : '' }}">
                                <td class="px-3 py-2 font-semibold">{{ $e->queue_number }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('patients.show', $e->patient) }}" wire:navigate class="hover:underline">{{ $e->patient->full_name }}</a>
                                    <div class="text-xs text-neutral-500"><span class="font-mono">{{ $e->encounter_number }}</span> &middot; {{ ucfirst($e->payer_type) }} @if ($e->priority !== 'normal') &middot; <span class="text-red-600">{{ ucfirst($e->priority) }}</span> @endif</div>
                                </td>
                                <td class="px-3 py-2"><flux:badge size="sm" :color="EncounterStage::color($e->stage)">{{ EncounterStage::label($e->stage) }}</flux:badge></td>
                                <td class="px-3 py-2">{{ $e->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-neutral-500">{{ $e->started_at->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($e->status === 'open' && in_array($e->stage, [EncounterStage::WAITING_TRIAGE, EncounterStage::WAITING_DOCTOR]))
                                        <flux:button size="xs" variant="ghost" wire:click="cancelVisit({{ $e->id }})" wire:confirm="{{ __('Cancel this visit?') }}">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('Queue is empty.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
