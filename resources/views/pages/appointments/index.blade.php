<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\EncounterService;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Appointments')] class extends Component {
    #[Url]
    public string $date = '';

    #[Url]
    public ?int $doctor = null;

    #[Url(as: 'patient')]
    public ?int $patient_id = null;

    public bool $showForm = false;

    public string $patientSearch = '';

    public ?int $form_doctor_id = null;

    public ?int $department_id = null;

    public string $scheduled_date = '';

    public string $scheduled_time = '09:00';

    public int $duration_minutes = 15;

    public string $type = 'consultation';

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['appointments.view', 'appointments.manage']), 403);

        $this->date = $this->date ?: now()->toDateString();
        $this->scheduled_date = $this->date;

        if (! Auth::user()->can('appointments.manage') && Auth::user()->can('encounters.create')) {
            $this->doctor ??= Auth::id();
        }

        if ($this->patient_id && Auth::user()->can('appointments.manage')) {
            $this->showForm = true;
        }
    }

    #[Computed]
    public function doctors()
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant->users()->wherePivot('status', 'active')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->can('encounters.create'))->values();
    }

    #[Computed]
    public function departments()
    {
        return Department::where('active', true)->where('type', 'clinical')->orderBy('name')->get();
    }

    #[Computed]
    public function selectedPatient()
    {
        return $this->patient_id ? Patient::find($this->patient_id) : null;
    }

    #[Computed]
    public function patientMatches()
    {
        if (strlen(trim($this->patientSearch)) < 2) {
            return collect();
        }
        $t = trim($this->patientSearch);

        return Patient::where(fn ($q) => $q->where('mrn', 'like', "%$t%")->orWhere('first_name', 'like', "%$t%")->orWhere('last_name', 'like', "%$t%")->orWhere('phone', 'like', "%$t%"))
            ->limit(8)->get();
    }

    #[Computed]
    public function appointments()
    {
        $day = Carbon::parse($this->date);

        return Appointment::with(['patient', 'doctor', 'department'])
            ->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->when($this->doctor, fn ($q) => $q->where('doctor_id', $this->doctor))
            ->orderBy('scheduled_at')
            ->get();
    }

    public function selectPatient(int $id): void
    {
        $this->patient_id = Patient::findOrFail($id)->id;
        $this->patientSearch = '';
    }

    public function shiftDay(int $days): void
    {
        $this->date = Carbon::parse($this->date)->addDays($days)->toDateString();
    }

    public function book(NumberSequenceService $numbers): void
    {
        abort_unless(Auth::user()->can('appointments.manage'), 403);

        $data = $this->validate([
            'patient_id' => ['required', 'integer'],
            'form_doctor_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'type' => ['required', 'in:consultation,follow_up,procedure,review'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $patient = Patient::findOrFail($data['patient_id']);
        $doctorId = $data['form_doctor_id'] && $this->doctors->contains('id', $data['form_doctor_id']) ? $data['form_doctor_id'] : null;
        $departmentId = $data['department_id'] ? Department::findOrFail($data['department_id'])->id : null;
        $start = Carbon::parse($data['scheduled_date'].' '.$data['scheduled_time']);
        $end = $start->copy()->addMinutes($data['duration_minutes']);

        if ($doctorId) {
            $clash = Appointment::where('doctor_id', $doctorId)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->whereBetween('scheduled_at', [$start->copy()->subHours(4), $end])
                ->get()
                ->first(fn ($a) => $a->scheduled_at < $end && $a->scheduled_at->copy()->addMinutes($a->duration_minutes) > $start);

            if ($clash) {
                $this->addError('scheduled_time', __('The doctor already has :patient booked at :time.', ['patient' => $clash->patient->full_name, 'time' => $clash->scheduled_at->format('H:i')]));

                return;
            }
        }

        Appointment::create([
            'facility_id' => app(TenantContext::class)->facilityId(),
            'patient_id' => $patient->id,
            'doctor_id' => $doctorId,
            'department_id' => $departmentId,
            'appointment_number' => $numbers->next('APT'),
            'scheduled_at' => $start,
            'duration_minutes' => $data['duration_minutes'],
            'type' => $data['type'],
            'status' => 'booked',
            'reason' => $data['reason'] ?: null,
            'booked_by' => Auth::id(),
        ]);

        Flux::toast(variant: 'success', text: __('Appointment booked for :name.', ['name' => $patient->full_name]));
        $this->date = $start->toDateString();
        $this->reset(['showForm', 'patient_id', 'reason', 'form_doctor_id']);
        unset($this->appointments);
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(Auth::user()->can('appointments.manage'), 403);
        abort_unless(in_array($status, ['confirmed', 'cancelled', 'no_show'], true), 422);

        $appointment = Appointment::findOrFail($id);

        if (in_array($appointment->status, ['checked_in', 'completed'], true)) {
            Flux::toast(variant: 'danger', text: __('This appointment has already been attended.'));

            return;
        }

        $appointment->update(['status' => $status] + ($status === 'cancelled' ? ['cancelled_at' => now(), 'cancellation_reason' => __('Cancelled at reception')] : []));
        unset($this->appointments);
    }

    public function checkIn(int $id, EncounterService $encounters): void
    {
        abort_unless(Auth::user()->can('reception.checkin'), 403);

        $appointment = Appointment::findOrFail($id);
        $encounter = $encounters->checkIn($appointment->patient, [], $appointment);

        Flux::toast(variant: 'success', text: __('Checked in — queue number :n', ['n' => $encounter->queue_number]));
        unset($this->appointments);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Appointments') }}</flux:heading>
        @can('appointments.manage')
            <flux:button variant="primary" icon="plus" wire:click="$toggle('showForm')">{{ __('Book appointment') }}</flux:button>
        @endcan
    </div>

    @if ($showForm)
        <form wire:submit="book" class="grid max-w-3xl gap-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            @if ($this->selectedPatient)
                <div class="flex items-center justify-between rounded-lg bg-neutral-100 px-3 py-2 text-sm dark:bg-neutral-800">
                    <span><span class="font-mono">{{ $this->selectedPatient->mrn }}</span> — {{ $this->selectedPatient->full_name }}</span>
                    <flux:button size="xs" variant="ghost" wire:click="$set('patient_id', null)">{{ __('Change') }}</flux:button>
                </div>
            @else
                <div>
                    <flux:input wire:model.live.debounce.300ms="patientSearch" icon="magnifying-glass" :label="__('Patient')" :placeholder="__('Search MRN, name or phone')" />
                    @error('patient_id') <flux:text class="text-red-500">{{ __('Select a patient.') }}</flux:text> @enderror
                    <div class="mt-1 divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
                        @foreach ($this->patientMatches as $p)
                            <button type="button" wire:key="pm-{{ $p->id }}" wire:click="selectPatient({{ $p->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                <span class="font-mono">{{ $p->mrn }}</span> — {{ $p->full_name }} <span class="text-neutral-500">{{ $p->phone }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form_doctor_id" :label="__('Doctor')">
                    <flux:select.option value="">{{ __('Any available') }}</flux:select.option>
                    @foreach ($this->doctors as $d)
                        <flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="department_id" :label="__('Clinic / department')">
                    <flux:select.option value="">{{ __('General outpatient') }}</flux:select.option>
                    @foreach ($this->departments as $dep)
                        <flux:select.option value="{{ $dep->id }}">{{ $dep->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-4 sm:grid-cols-4">
                <flux:input wire:model="scheduled_date" type="date" :label="__('Date')" />
                <flux:input wire:model="scheduled_time" type="time" :label="__('Time')" />
                <flux:input wire:model="duration_minutes" type="number" min="5" :label="__('Minutes')" />
                <flux:select wire:model="type" :label="__('Type')">
                    <flux:select.option value="consultation">{{ __('Consultation') }}</flux:select.option>
                    <flux:select.option value="follow_up">{{ __('Follow-up') }}</flux:select.option>
                    <flux:select.option value="review">{{ __('Review') }}</flux:select.option>
                    <flux:select.option value="procedure">{{ __('Procedure') }}</flux:select.option>
                </flux:select>
            </div>
            <flux:input wire:model="reason" :label="__('Reason')" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Book') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <flux:button size="sm" icon="chevron-left" wire:click="shiftDay(-1)" />
        <flux:input wire:model.live="date" type="date" class="max-w-48" />
        <flux:button size="sm" icon="chevron-right" wire:click="shiftDay(1)" />
        <flux:select wire:model.live="doctor" class="max-w-64">
            <flux:select.option value="">{{ __('All doctors') }}</flux:select.option>
            @foreach ($this->doctors as $d)
                <flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-4 py-2">{{ __('Time') }}</th><th class="px-4 py-2">{{ __('Patient') }}</th><th class="px-4 py-2">{{ __('Doctor') }}</th><th class="px-4 py-2">{{ __('Type') }}</th><th class="px-4 py-2">{{ __('Reason') }}</th><th class="px-4 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->appointments as $a)
                    <tr wire:key="a-{{ $a->id }}">
                        <td class="px-4 py-2 font-medium">{{ $a->scheduled_at->format('H:i') }} <span class="text-xs text-neutral-500">({{ $a->duration_minutes }}m)</span></td>
                        <td class="px-4 py-2"><a href="{{ route('patients.show', $a->patient) }}" wire:navigate class="hover:underline">{{ $a->patient->full_name }}</a> <span class="font-mono text-xs text-neutral-500">{{ $a->patient->mrn }}</span></td>
                        <td class="px-4 py-2">{{ $a->doctor?->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ str_replace('_', ' ', ucfirst($a->type)) }}</td>
                        <td class="px-4 py-2">{{ $a->reason ?? '—' }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="match($a->status) { 'checked_in', 'completed' => 'lime', 'cancelled', 'no_show' => 'zinc', 'confirmed' => 'sky', default => 'amber' }">{{ str_replace('_', ' ', $a->status) }}</flux:badge></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if (in_array($a->status, ['booked', 'confirmed']))
                                @can('reception.checkin')
                                    <flux:button size="xs" variant="primary" wire:click="checkIn({{ $a->id }})">{{ __('Check in') }}</flux:button>
                                @endcan
                                @can('appointments.manage')
                                    @if ($a->status === 'booked')
                                        <flux:button size="xs" variant="ghost" wire:click="setStatus({{ $a->id }}, 'confirmed')">{{ __('Confirm') }}</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" wire:click="setStatus({{ $a->id }}, 'no_show')">{{ __('No-show') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="setStatus({{ $a->id }}, 'cancelled')" wire:confirm="{{ __('Cancel this appointment?') }}">{{ __('Cancel') }}</flux:button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-neutral-500">{{ __('No appointments on this day.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
