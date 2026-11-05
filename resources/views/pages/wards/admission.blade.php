<?php

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Medicine;
use App\Services\Clinical\TriageService;
use App\Services\Inpatient\AdmissionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inpatient chart')] class extends Component {
    public Admission $admission;

    public string $tab = 'nursing';

    public string $nursing_note = '';

    public string $shift = 'morning';

    public array $vitals = ['temperature' => '', 'pulse' => '', 'respiratory_rate' => '', 'systolic_bp' => '', 'diastolic_bp' => '', 'spo2' => '', 'pain_score' => '', 'notes' => ''];

    public ?int $mar_item_id = null;

    public string $mar_status = 'given';

    public string $mar_notes = '';

    public ?int $transfer_bed_id = null;

    public string $transfer_reason = '';

    public string $discharge_type = 'routine';

    public string $discharge_summary = '';

    public ?string $follow_up_date = null;

    public function mount(Admission $admission): void
    {
        abort_unless(Auth::user()->canAny(['wards.view', 'nursing.tasks.manage']), 403);
        $this->admission = $admission;
    }

    #[Computed]
    public function patient()
    {
        return $this->admission->patient()->with('allergies')->first();
    }

    #[Computed]
    public function notes()
    {
        return $this->admission->nursingNotes()->with('author')->latest()->get();
    }

    #[Computed]
    public function vitalsHistory()
    {
        return \App\Models\VitalSign::where('patient_id', $this->admission->patient_id)
            ->where('recorded_at', '>=', $this->admission->admitted_at ?? $this->admission->requested_at)
            ->latest('recorded_at')->limit(30)->get();
    }

    #[Computed]
    public function activeMedications()
    {
        return \App\Models\PrescriptionItem::with(['medicine', 'prescription'])
            ->whereHas('prescription', fn ($q) => $q->where('encounter_id', $this->admission->encounter_id)->where('status', '!=', 'cancelled'))
            ->where('status', '!=', 'cancelled')
            ->get();
    }

    #[Computed]
    public function administrations()
    {
        return $this->admission->medicationAdministrations()->with(['medicine', 'administeredBy'])->latest('administered_at')->limit(50)->get();
    }

    #[Computed]
    public function availableBeds()
    {
        return Bed::with('ward')->where('status', 'available')->orderBy('ward_id')->get();
    }

    #[Computed]
    public function bedHistory()
    {
        return $this->admission->bedAssignments()->with('bed.ward')->orderBy('assigned_at')->get();
    }

    protected function ensureAdmitted(): void
    {
        abort_unless($this->admission->status === 'admitted', 422, __('Patient is not currently admitted.'));
    }

    public function addNote(AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('nursing.tasks.manage'), 403);
        $this->ensureAdmitted();
        $this->validate(['nursing_note' => ['required', 'string', 'max:5000'], 'shift' => ['required', 'in:morning,afternoon,night']]);
        $admissions->addNursingNote($this->admission, $this->nursing_note, $this->shift);
        $this->reset('nursing_note');
        unset($this->notes);
    }

    public function recordVitals(TriageService $triage): void
    {
        abort_unless(Auth::user()->canAny(['nursing.tasks.manage', 'triage.record']), 403);
        $this->ensureAdmitted();

        $this->validate([
            'vitals.temperature' => ['nullable', 'numeric', 'between:25,45'],
            'vitals.pulse' => ['nullable', 'integer', 'between:20,250'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'vitals.systolic_bp' => ['nullable', 'integer', 'between:40,300'],
            'vitals.diastolic_bp' => ['nullable', 'integer', 'between:20,200'],
            'vitals.spo2' => ['nullable', 'integer', 'between:40,100'],
            'vitals.pain_score' => ['nullable', 'integer', 'between:0,10'],
            'vitals.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $record = \App\Models\VitalSign::create(array_map(fn ($v) => $v === '' ? null : $v, $this->vitals) + [
            'patient_id' => $this->admission->patient_id,
            'encounter_id' => $this->admission->encounter_id,
            'admission_id' => $this->admission->id,
            'recorded_by' => Auth::id(),
            'recorded_at' => now(),
        ]);
        $this->vitals = array_fill_keys(array_keys($this->vitals), '');
        unset($this->vitalsHistory);

        if (in_array(TriageService::suggestCategory($record), ['red', 'orange'], true)) {
            Flux::toast(variant: 'danger', text: __('Early-warning score is high — escalate to the doctor.'));
        }
    }

    public function chartMedication(AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('nursing.tasks.manage'), 403);
        $this->validate(['mar_item_id' => ['required', 'integer'], 'mar_status' => ['required', 'in:given,withheld,refused'], 'mar_notes' => ['nullable', 'string', 'max:255']]);

        $item = $this->activeMedications->firstWhere('id', $this->mar_item_id);
        abort_unless($item, 404);

        $admissions->recordAdministration($this->admission, [
            'prescription_item_id' => $item->id,
            'medicine_id' => $item->medicine_id,
            'dose' => $item->dose,
            'route' => $item->route,
            'status' => $this->mar_status,
            'notes' => $this->mar_notes ?: null,
        ]);

        $this->reset(['mar_item_id', 'mar_notes']);
        unset($this->administrations);
        Flux::toast(variant: 'success', text: __('Medication charted.'));
    }

    public function transfer(AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('wards.transfers.approve') || Auth::user()->can('wards.beds.allocate'), 403);
        $this->validate(['transfer_bed_id' => ['required', 'integer'], 'transfer_reason' => ['required', 'string', 'max:255']]);
        $this->admission = $admissions->transfer($this->admission, $this->transfer_bed_id, $this->transfer_reason)->fresh();
        $this->reset(['transfer_bed_id', 'transfer_reason']);
        unset($this->availableBeds, $this->bedHistory);
        Flux::toast(variant: 'success', text: __('Patient transferred.'));
    }

    public function discharge(AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('wards.discharge'), 403);
        $this->validate([
            'discharge_type' => ['required', 'in:routine,against_advice,referred,deceased,absconded'],
            'discharge_summary' => ['required', 'string', 'max:10000'],
            'follow_up_date' => ['nullable', 'date', 'after:today'],
        ]);

        $this->admission = $admissions->discharge($this->admission, [
            'discharge_type' => $this->discharge_type,
            'discharge_summary' => $this->discharge_summary,
            'follow_up_date' => $this->follow_up_date,
        ])->fresh();

        Flux::toast(variant: 'success', text: __('Patient discharged. Bed charges posted and bed sent for cleaning.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Inpatient chart') }}</flux:heading>
            <flux:text>
                <span class="font-mono">{{ $admission->admission_number }}</span> · {{ $admission->ward?->name }} {{ __('bed') }} {{ $admission->bed?->bed_number }}
                · <flux:badge size="sm">{{ $admission->status }}</flux:badge>
                @if ($admission->admitted_at) · {{ __('admitted') }} {{ $admission->admitted_at->format('d M Y H:i') }} ({{ __('day') }} {{ $admission->lengthOfStayDays() }}) @endif
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            @php($facility = app(\App\Services\Tenancy\TenantContext::class)->facility())
            @if (in_array($admission->ward?->type, \App\Support\FacilityLevel::CRITICAL_CARE_WARD_TYPES, true) && \App\Support\ModuleRegistry::enabledAt($facility, 'critical-care'))
                @can('critical_care.chart')
                    <flux:button size="sm" variant="primary" icon="heart" :href="route('critical-care.chart', $admission)" wire:navigate>{{ __('ICU / HDU chart') }}</flux:button>
                @endcan
            @endif
            @if ($admission->status === 'admitted' && \App\Support\ModuleRegistry::enabledAt($facility, 'referrals'))
                @can('referrals.manage')
                    <flux:button size="sm" icon="arrows-right-left" :href="route('referrals.create', ['direction' => 'outgoing', 'encounter' => $admission->encounter_id])" wire:navigate>{{ __('Refer') }}</flux:button>
                @endcan
            @endif
            @can('encounters.view')
                <flux:button size="sm" :href="route('consultation.show', $admission->encounter_id)" wire:navigate>{{ __('Doctor workspace') }}</flux:button>
            @endcan
        </div>
    </div>

    <x-patient-banner :patient="$this->patient" />

    <div class="rounded-xl border border-neutral-200 p-3 text-sm dark:border-neutral-700"><span class="text-neutral-500">{{ __('Reason for admission') }}:</span> {{ $admission->reason }}</div>

    <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['nursing' => __('Nursing notes'), 'vitals' => __('Observations'), 'mar' => __('Medication chart'), 'transfer' => __('Bed / transfer'), 'discharge' => __('Discharge')] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'nursing')
        <div class="grid gap-6 lg:grid-cols-2">
            @if ($admission->status === 'admitted')
                @can('nursing.tasks.manage')
                    <form wire:submit="addNote" class="grid content-start gap-3">
                        <flux:select wire:model="shift" :label="__('Shift')">
                            <flux:select.option value="morning">{{ __('Morning') }}</flux:select.option>
                            <flux:select.option value="afternoon">{{ __('Afternoon') }}</flux:select.option>
                            <flux:select.option value="night">{{ __('Night') }}</flux:select.option>
                        </flux:select>
                        <flux:textarea wire:model="nursing_note" rows="5" :label="__('Note')" />
                        <div><flux:button type="submit" variant="primary" size="sm">{{ __('Add note') }}</flux:button></div>
                    </form>
                @endcan
            @endif
            <div class="space-y-2">
                @forelse ($this->notes as $n)
                    <div wire:key="nn-{{ $n->id }}" class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="text-xs text-neutral-500">{{ $n->created_at->format('d M H:i') }} · {{ ucfirst($n->shift) }} · {{ $n->author?->name }}</div>
                        <div class="mt-1">{!! nl2br(e($n->note)) !!}</div>
                    </div>
                @empty
                    <flux:text>{{ __('No nursing notes yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endif

    @if ($tab === 'vitals')
        @if ($admission->status === 'admitted')
            @canany(['nursing.tasks.manage', 'triage.record'])
                <form wire:submit="recordVitals" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-7">
                        <flux:input size="sm" wire:model="vitals.temperature" type="number" step="0.1" :label="__('Temp')" />
                        <flux:input size="sm" wire:model="vitals.pulse" type="number" :label="__('Pulse')" />
                        <flux:input size="sm" wire:model="vitals.respiratory_rate" type="number" :label="__('RR')" />
                        <flux:input size="sm" wire:model="vitals.systolic_bp" type="number" :label="__('Sys')" />
                        <flux:input size="sm" wire:model="vitals.diastolic_bp" type="number" :label="__('Dia')" />
                        <flux:input size="sm" wire:model="vitals.spo2" type="number" :label="__('SpO₂')" />
                        <flux:input size="sm" wire:model="vitals.pain_score" type="number" :label="__('Pain')" />
                    </div>
                    <flux:input size="sm" wire:model="vitals.notes" :label="__('Notes')" />
                    <div><flux:button type="submit" size="sm" variant="primary">{{ __('Record observations') }}</flux:button></div>
                </form>
            @endcanany
        @endif
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900"><tr><th class="px-3 py-2">{{ __('Time') }}</th><th class="px-3 py-2">T</th><th class="px-3 py-2">HR</th><th class="px-3 py-2">RR</th><th class="px-3 py-2">BP</th><th class="px-3 py-2">SpO₂</th><th class="px-3 py-2">{{ __('Pain') }}</th><th class="px-3 py-2">{{ __('Notes') }}</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($this->vitalsHistory as $v)
                        <tr wire:key="v-{{ $v->id }}"><td class="px-3 py-1">{{ $v->recorded_at->format('d M H:i') }}</td><td class="px-3 py-1">{{ $v->temperature }}</td><td class="px-3 py-1">{{ $v->pulse }}</td><td class="px-3 py-1">{{ $v->respiratory_rate }}</td><td class="px-3 py-1">{{ $v->bloodPressure() }}</td><td class="px-3 py-1">{{ $v->spo2 }}</td><td class="px-3 py-1">{{ $v->pain_score }}</td><td class="px-3 py-1">{{ $v->notes }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'mar')
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-2">
                <flux:heading level="3">{{ __('Prescribed medication') }}</flux:heading>
                @forelse ($this->activeMedications as $m)
                    <div wire:key="am-{{ $m->id }}" class="rounded-lg border border-neutral-200 p-2 text-sm dark:border-neutral-700">{{ $m->medicine->displayName() }} — {{ $m->dose }} {{ $m->frequency }} {{ $m->route }} <span class="text-xs text-neutral-500">({{ $m->status }})</span></div>
                @empty
                    <flux:text>{{ __('No medication prescribed on this admission.') }}</flux:text>
                @endforelse

                @if ($admission->status === 'admitted' && $this->activeMedications->isNotEmpty())
                    @can('nursing.tasks.manage')
                        <form wire:submit="chartMedication" class="mt-3 grid gap-2 rounded-xl border border-neutral-200 p-3 dark:border-neutral-700">
                            <flux:select size="sm" wire:model="mar_item_id" :label="__('Medication')">
                                <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                                @foreach ($this->activeMedications as $m)
                                    <flux:select.option value="{{ $m->id }}">{{ $m->medicine->displayName() }} {{ $m->dose }} {{ $m->route }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select size="sm" wire:model="mar_status" :label="__('Outcome')">
                                <flux:select.option value="given">{{ __('Given') }}</flux:select.option>
                                <flux:select.option value="withheld">{{ __('Withheld') }}</flux:select.option>
                                <flux:select.option value="refused">{{ __('Refused by patient') }}</flux:select.option>
                            </flux:select>
                            <flux:input size="sm" wire:model="mar_notes" :label="__('Notes')" />
                            <div><flux:button size="sm" type="submit" variant="primary">{{ __('Chart') }}</flux:button></div>
                        </form>
                    @endcan
                @endif
            </div>
            <div class="space-y-1 text-sm">
                <flux:heading level="3">{{ __('Administration record') }}</flux:heading>
                @forelse ($this->administrations as $a)
                    <div wire:key="ma-{{ $a->id }}" class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800">
                        <span>{{ $a->administered_at->format('d M H:i') }} — {{ $a->medicine->displayName() }} {{ $a->dose }}</span>
                        <span><flux:badge size="sm" :color="$a->status === 'given' ? 'lime' : 'amber'">{{ $a->status }}</flux:badge> {{ $a->administeredBy?->name }}</span>
                    </div>
                @empty
                    <flux:text>{{ __('Nothing charted yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endif

    @if ($tab === 'transfer')
        <div class="grid gap-6 lg:grid-cols-2">
            @if ($admission->status === 'admitted')
                @canany(['wards.transfers.approve', 'wards.beds.allocate'])
                    <form wire:submit="transfer" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <flux:select wire:model="transfer_bed_id" :label="__('Transfer to bed')">
                            <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                            @foreach ($this->availableBeds as $b)
                                <flux:select.option value="{{ $b->id }}">{{ $b->ward->name }} — {{ $b->bed_number }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="transfer_reason" :label="__('Reason')" />
                        <div><flux:button type="submit" size="sm" variant="primary">{{ __('Transfer') }}</flux:button></div>
                    </form>
                @endcanany
            @endif
            <div class="space-y-1 text-sm">
                <flux:heading level="3">{{ __('Bed history') }}</flux:heading>
                @foreach ($this->bedHistory as $h)
                    <div wire:key="bh-{{ $h->id }}">{{ $h->assigned_at->format('d M H:i') }} → {{ $h->released_at?->format('d M H:i') ?? __('now') }}: {{ $h->bed->ward->name }} {{ $h->bed->bed_number }} <span class="text-neutral-500">({{ $h->reason }})</span></div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($tab === 'discharge')
        @if ($admission->status === 'discharged')
            <div class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                <div><span class="text-neutral-500">{{ __('Discharged') }}:</span> {{ $admission->discharged_at->format('d M Y H:i') }} ({{ str_replace('_', ' ', $admission->discharge_type) }})</div>
                @if ($admission->follow_up_date) <div><span class="text-neutral-500">{{ __('Follow-up') }}:</span> {{ $admission->follow_up_date->format('d M Y') }}</div> @endif
                <div class="mt-2">{!! nl2br(e($admission->discharge_summary)) !!}</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" icon="printer" :href="route('print.discharge', $admission)" target="_blank">{{ __('Print discharge summary') }}</flux:button>
                    @if ($admission->discharge_type === 'deceased')
                        @if ($death = \App\Models\DeathNotification::where('admission_id', $admission->id)->first())
                            <flux:button size="sm" icon="printer" :href="route('print.death', $death)" target="_blank">{{ __('Print death notification') }}</flux:button>
                        @elseif (\App\Support\ModuleRegistry::enabledAt(app(\App\Services\Tenancy\TenantContext::class)->facility(), 'mortuary'))
                            <flux:button size="sm" icon="document-plus" :href="route('mortuary.index', ['admission' => $admission->id])" wire:navigate>{{ __('Record death notification') }}</flux:button>
                        @endif
                    @endif
                </div>
            </div>
        @elseif ($admission->status === 'admitted')
            @can('wards.discharge')
                <form wire:submit="discharge" class="grid max-w-3xl gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:select wire:model="discharge_type" :label="__('Discharge type')">
                            <flux:select.option value="routine">{{ __('Routine') }}</flux:select.option>
                            <flux:select.option value="referred">{{ __('Referred') }}</flux:select.option>
                            <flux:select.option value="against_advice">{{ __('Against medical advice') }}</flux:select.option>
                            <flux:select.option value="absconded">{{ __('Absconded') }}</flux:select.option>
                            <flux:select.option value="deceased">{{ __('Deceased') }}</flux:select.option>
                        </flux:select>
                        <flux:input type="date" wire:model="follow_up_date" :label="__('Follow-up date')" />
                    </div>
                    <flux:textarea wire:model="discharge_summary" rows="8" :label="__('Discharge summary (diagnosis, treatment, discharge medication, instructions)')" />
                    <div><flux:button type="submit" variant="primary" wire:confirm="{{ __('Discharge this patient? Bed charges will be posted.') }}">{{ __('Discharge patient') }}</flux:button></div>
                </form>
            @else
                <flux:text>{{ __('Discharge must be authorised by a clinician.') }}</flux:text>
            @endcan
        @endif
    @endif
</div>
