<?php

use App\Models\ClinicalNote;
use App\Models\Encounter;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\RadiologyExam;
use App\Models\Ward;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\EncounterService;
use App\Services\Diagnostics\LaboratoryService;
use App\Services\Diagnostics\RadiologyService;
use App\Services\Inpatient\AdmissionService;
use App\Services\Pharmacy\PharmacyService;
use App\Support\EncounterStage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Consultation')] class extends Component {
    public Encounter $encounter;

    #[Url]
    public string $tab = 'notes';

    // Notes
    public ?int $editingNoteId = null;

    public ?int $amendingNoteId = null;

    public string $amendment_reason = '';

    public array $note = ['presenting_complaint' => '', 'history' => '', 'examination' => '', 'assessment' => '', 'plan' => ''];

    // Diagnosis / allergy
    public string $dx_code = '';

    public string $dx_description = '';

    public string $dx_type = 'primary';

    public string $dx_certainty = 'confirmed';

    public string $allergen = '';

    public string $allergy_reaction = '';

    public string $allergy_severity = 'moderate';

    // Orders
    public array $labTestIds = [];

    public string $labPriority = 'routine';

    public string $labNotes = '';

    public string $labSearch = '';

    public ?int $radiologyExamId = null;

    public string $radPriority = 'routine';

    public string $radIndication = '';

    // Prescription
    public string $medSearch = '';

    public array $rxItems = [];

    public string $rxNotes = '';

    // Disposition
    public ?int $admit_ward_id = null;

    public string $admit_reason = '';

    public string $admit_type = 'planned';

    public function mount(Encounter $encounter): void
    {
        abort_unless(Auth::user()->can('encounters.view'), 403);
        $this->encounter = $encounter;
    }

    protected function canWrite(): bool
    {
        return Auth::user()->can('encounters.update') && $this->encounter->isOpen();
    }

    protected function refreshEncounter(): void
    {
        $this->encounter = $this->encounter->fresh();
    }

    #[Computed]
    public function patient()
    {
        return $this->encounter->patient()->with('allergies')->first();
    }

    #[Computed]
    public function vitals()
    {
        return $this->encounter->vitalSigns()->with('recordedBy')->latest('recorded_at')->get();
    }

    #[Computed]
    public function notes()
    {
        return $this->encounter->notes()->with('author')->latest()->get();
    }

    #[Computed]
    public function diagnoses()
    {
        return $this->encounter->diagnoses()->latest()->get();
    }

    #[Computed]
    public function labOrders()
    {
        return $this->encounter->labOrders()->with(['items.test', 'orderedBy'])->latest()->get();
    }

    #[Computed]
    public function radiologyOrders()
    {
        return $this->encounter->radiologyOrders()->with(['exam', 'orderedBy'])->latest()->get();
    }

    #[Computed]
    public function prescriptions()
    {
        return $this->encounter->prescriptions()->with(['items.medicine', 'prescriber'])->latest()->get();
    }

    #[Computed]
    public function admissions()
    {
        return $this->encounter->admissions()->with(['ward', 'bed'])->latest()->get();
    }

    #[Computed]
    public function transitions()
    {
        return $this->encounter->transitions()->with('actor')->latest('created_at')->get();
    }

    #[Computed]
    public function labTests()
    {
        return LabTest::where('active', true)
            ->when($this->labSearch, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->labSearch}%")->orWhere('code', 'like', "%{$this->labSearch}%")->orWhere('category', 'like', "%{$this->labSearch}%")))
            ->orderBy('category')->orderBy('name')->limit(40)->get();
    }

    #[Computed]
    public function radiologyExams()
    {
        return RadiologyExam::where('active', true)->orderBy('modality')->orderBy('name')->get();
    }

    #[Computed]
    public function medicineMatches()
    {
        $t = trim($this->medSearch);
        if (strlen($t) < 2) {
            return collect();
        }

        return Medicine::where('active', true)
            ->where(fn ($q) => $q->where('generic_name', 'like', "%$t%")->orWhere('brand_name', 'like', "%$t%")->orWhere('code', 'like', "%$t%"))
            ->limit(8)->get();
    }

    #[Computed]
    public function wards()
    {
        return Ward::where('active', true)->withCount(['beds as available_beds' => fn ($q) => $q->where('status', 'available')])->orderBy('name')->get();
    }

    // ---- Workflow ----

    public function startConsultation(ConsultationService $consultation): void
    {
        abort_unless(Auth::user()->can('encounters.create') && $this->encounter->isOpen(), 403);
        $this->encounter = $consultation->start($this->encounter);
        Flux::toast(text: __('Consultation started.'));
    }

    public function moveTo(string $stage, EncounterService $encounters): void
    {
        abort_unless($this->canWrite(), 403);
        abort_unless(in_array($stage, [EncounterStage::AWAITING_RESULTS, EncounterStage::WAITING_PHARMACY, EncounterStage::WAITING_BILLING, EncounterStage::IN_CONSULTATION], true), 422);

        $encounters->transition($this->encounter, $stage, __('Sent by doctor'));
        $this->refreshEncounter();
        Flux::toast(variant: 'success', text: __('Patient moved to: :stage', ['stage' => EncounterStage::label($stage)]));
    }

    public function completeVisit(EncounterService $encounters): void
    {
        abort_unless($this->canWrite() && Auth::user()->can('encounters.sign'), 403);

        if ($this->encounter->diagnoses()->doesntExist()) {
            Flux::toast(variant: 'danger', text: __('Record at least one diagnosis before closing the visit.'));

            return;
        }

        if ($this->encounter->notes()->where('status', 'draft')->exists()) {
            Flux::toast(variant: 'danger', text: __('Sign or discard draft notes before closing the visit.'));

            return;
        }

        $encounters->complete($this->encounter);
        $this->refreshEncounter();
        Flux::toast(variant: 'success', text: __('Visit completed.'));
    }

    // ---- Notes ----

    public function editNote(int $id): void
    {
        $note = $this->encounter->notes()->findOrFail($id);
        abort_if($note->isSigned() || $note->author_id !== Auth::id(), 403);
        $this->editingNoteId = $note->id;
        $this->amendingNoteId = null;
        $this->note = $note->only(array_keys($this->note));
        $this->note = array_map(fn ($v) => (string) $v, $this->note);
    }

    public function startAmend(int $id): void
    {
        $note = $this->encounter->notes()->findOrFail($id);
        abort_unless($note->status === 'signed' && Auth::user()->can('clinical_notes.amend'), 403);
        $this->amendingNoteId = $note->id;
        $this->editingNoteId = null;
        $this->note = array_map(fn ($v) => (string) $v, $note->only(array_keys($this->note)));
    }

    public function cancelEdit(): void
    {
        $this->reset(["editingNoteId", "amendingNoteId", "amendment_reason"]);
        $this->note = array_fill_keys(array_keys($this->note), "");
    }

    public function saveNote(bool $sign = false): void
    {
        $consultation = app(ConsultationService::class);
        abort_unless(Auth::user()->can('clinical_notes.create') && $this->encounter->isOpen(), 403);

        $data = $this->validate([
            'note.presenting_complaint' => ['nullable', 'string', 'max:5000'],
            'note.history' => ['nullable', 'string', 'max:10000'],
            'note.examination' => ['nullable', 'string', 'max:10000'],
            'note.assessment' => ['nullable', 'string', 'max:5000'],
            'note.plan' => ['nullable', 'string', 'max:5000'],
        ])['note'];

        if (! array_filter($data)) {
            $this->addError('note.presenting_complaint', __('The note is empty.'));

            return;
        }

        if ($this->amendingNoteId) {
            $this->validate(['amendment_reason' => ['required', 'string', 'max:255']]);
            $consultation->amend($this->encounter->notes()->findOrFail($this->amendingNoteId), $data, $this->amendment_reason);
            Flux::toast(variant: 'success', text: __('Amendment saved and signed.'));
        } else {
            $existing = $this->editingNoteId ? $this->encounter->notes()->findOrFail($this->editingNoteId) : null;
            $note = $consultation->saveNote($this->encounter, $data, $existing);

            if ($sign) {
                abort_unless(Auth::user()->can('clinical_notes.sign'), 403);
                $consultation->sign($note);
            }

            Flux::toast(variant: 'success', text: $sign ? __('Note signed.') : __('Draft saved.'));
        }

        $this->reset(['editingNoteId', 'amendingNoteId', 'amendment_reason']);
        $this->note = array_fill_keys(array_keys($this->note), '');
        unset($this->notes);
    }

    public function signNote(int $id, ConsultationService $consultation): void
    {
        abort_unless(Auth::user()->can('clinical_notes.sign'), 403);
        $consultation->sign($this->encounter->notes()->findOrFail($id));
        unset($this->notes);
    }

    public function discardDraft(int $id): void
    {
        $note = $this->encounter->notes()->where('status', 'draft')->where('author_id', Auth::id())->findOrFail($id);
        $note->delete();
        unset($this->notes);
    }

    // ---- Diagnoses / allergies ----

    public function addDiagnosis(ConsultationService $consultation): void
    {
        abort_unless(Auth::user()->can('clinical_notes.create') && $this->encounter->isOpen(), 403);

        $data = $this->validate([
            'dx_code' => ['nullable', 'string', 'max:20'],
            'dx_description' => ['required', 'string', 'max:255'],
            'dx_type' => ['required', 'in:primary,secondary,differential'],
            'dx_certainty' => ['required', 'in:provisional,confirmed'],
        ]);

        $code = $data['dx_code'] ? strtoupper(trim($data['dx_code'])) : null;

        // A code must come from the ICD-11 classification (SHA rejects unknown codes).
        if ($code && ! \App\Models\IcdCode::isValid($code)) {
            $this->addError('dx_code', __('":code" is not an ICD-11 code. Pick one from the search results.', ['code' => $code]));

            return;
        }

        $consultation->addDiagnosis($this->encounter, [
            'code' => $code,
            'code_system' => $code ? 'icd11' : 'free',
            'description' => $data['dx_description'],
            'type' => $data['dx_type'],
            'certainty' => $data['dx_certainty'],
        ]);

        $this->reset(['dx_code', 'dx_description', 'dx_search']);
        unset($this->diagnoses);
    }

    public string $dx_search = '';

    #[Computed]
    public function icdResults()
    {
        return mb_strlen(trim($this->dx_search)) >= 2
            ? \App\Models\IcdCode::search($this->dx_search)->limit(12)->get()
            : collect();
    }

    public function pickIcd(int $id): void
    {
        $icd = \App\Models\IcdCode::findOrFail($id);
        $this->dx_code = $icd->code;
        $this->dx_description = $this->dx_description ?: $icd->title;
        $this->dx_search = '';
    }

    public function removeDiagnosis(int $id): void
    {
        abort_unless(Auth::user()->can('clinical_notes.create') && $this->encounter->isOpen(), 403);
        $this->encounter->diagnoses()->where('recorded_by', Auth::id())->findOrFail($id)->delete();
        unset($this->diagnoses);
    }

    public function addAllergy(ConsultationService $consultation): void
    {
        abort_unless(Auth::user()->can('clinical_notes.create'), 403);

        $data = $this->validate([
            'allergen' => ['required', 'string', 'max:100'],
            'allergy_reaction' => ['nullable', 'string', 'max:150'],
            'allergy_severity' => ['required', 'in:mild,moderate,severe'],
        ]);

        $consultation->addAllergy($this->encounter, ['allergen' => $data['allergen'], 'reaction' => $data['allergy_reaction'] ?: null, 'severity' => $data['allergy_severity']]);
        $this->reset(['allergen', 'allergy_reaction']);
        unset($this->patient);
    }

    // ---- Orders ----

    public function orderLab(LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('orders.create'), 403);

        $this->validate([
            'labTestIds' => ['required', 'array', 'min:1'],
            'labTestIds.*' => ['integer'],
            'labPriority' => ['required', 'in:routine,urgent,stat'],
            'labNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $lab->order($this->encounter, $this->labTestIds, $this->labPriority, $this->labNotes ?: null);
        Flux::toast(variant: 'success', text: __('Lab order :n sent to the laboratory.', ['n' => $order->order_number]));

        $this->reset(['labTestIds', 'labNotes', 'labPriority']);
        unset($this->labOrders);
    }

    public function orderRadiology(RadiologyService $radiology): void
    {
        abort_unless(Auth::user()->can('orders.create'), 403);

        $this->validate([
            'radiologyExamId' => ['required', 'integer'],
            'radPriority' => ['required', 'in:routine,urgent,stat'],
            'radIndication' => ['required', 'string', 'max:500'],
        ]);

        $order = $radiology->order($this->encounter, $this->radiologyExamId, $this->radPriority, $this->radIndication);
        Flux::toast(variant: 'success', text: __('Imaging request :n sent.', ['n' => $order->order_number]));

        $this->reset(['radiologyExamId', 'radIndication', 'radPriority']);
        unset($this->radiologyOrders);
    }

    // ---- Prescriptions ----

    public function addMedicine(int $id, PharmacyService $pharmacy): void
    {
        $medicine = Medicine::where('active', true)->findOrFail($id);
        $conflicts = $pharmacy->allergyConflicts($this->encounter->patient_id, $medicine);

        $this->rxItems[] = [
            'medicine_id' => $medicine->id,
            'name' => $medicine->displayName(),
            'dose' => '1',
            'frequency' => 'BD',
            'route' => $medicine->form === 'injection' ? 'IV' : 'oral',
            'duration_days' => 5,
            'quantity' => 10,
            'instructions' => '',
            'stock' => $medicine->stockOnHand(),
            'allergy' => $conflicts->pluck('allergen')->join(', '),
        ];

        $this->medSearch = '';
    }

    public function updatedRxItems($value, $key): void
    {
        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if (in_array($field, ['frequency', 'duration_days', 'dose'], true) && isset($this->rxItems[$index])) {
            $perDay = ['OD' => 1, 'BD' => 2, 'TDS' => 3, 'QID' => 4, 'NOCTE' => 1, 'STAT' => 1, 'PRN' => 1][strtoupper($this->rxItems[$index]['frequency'])] ?? 1;
            $days = strtoupper($this->rxItems[$index]['frequency']) === 'STAT' ? 1 : max(1, (int) $this->rxItems[$index]['duration_days']);
            $dose = is_numeric($this->rxItems[$index]['dose']) ? (float) $this->rxItems[$index]['dose'] : 1;
            $this->rxItems[$index]['quantity'] = (int) ceil($perDay * $days * $dose);
        }
    }

    public function removeRxItem(int $index): void
    {
        unset($this->rxItems[$index]);
        $this->rxItems = array_values($this->rxItems);
    }

    public function prescribe(PharmacyService $pharmacy): void
    {
        abort_unless(Auth::user()->can('orders.create'), 403);

        $this->validate([
            'rxItems' => ['required', 'array', 'min:1'],
            'rxItems.*.dose' => ['required', 'string', 'max:50'],
            'rxItems.*.frequency' => ['required', 'in:OD,BD,TDS,QID,NOCTE,STAT,PRN'],
            'rxItems.*.route' => ['required', 'string', 'max:30'],
            'rxItems.*.duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'rxItems.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'rxItems.*.instructions' => ['nullable', 'string', 'max:255'],
            'rxNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $rx = $pharmacy->prescribe($this->encounter, array_map(fn ($i) => collect($i)->only(['medicine_id', 'dose', 'frequency', 'route', 'duration_days', 'quantity', 'instructions'])->all(), $this->rxItems), $this->rxNotes ?: null);

        Flux::toast(variant: 'success', text: __('Prescription :n sent to pharmacy.', ['n' => $rx->prescription_number]));
        $this->reset(['rxItems', 'rxNotes']);
        unset($this->prescriptions);
    }

    // ---- Admission ----

    public function requestAdmission(AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('wards.admissions.create'), 403);

        $data = $this->validate([
            'admit_ward_id' => ['nullable', 'integer'],
            'admit_reason' => ['required', 'string', 'max:1000'],
            'admit_type' => ['required', 'in:planned,emergency,direct'],
        ]);

        $admissions->request($this->encounter, [
            'ward_id' => $data['admit_ward_id'] ? Ward::findOrFail($data['admit_ward_id'])->id : null,
            'reason' => $data['admit_reason'],
            'type' => $data['admit_type'],
        ]);

        Flux::toast(variant: 'success', text: __('Admission requested. The ward has been notified.'));
        $this->reset(['admit_ward_id', 'admit_reason']);
        unset($this->admissions);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Consultation') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            @if ($encounter->isOpen())
                @can('encounters.create')
                    @if (in_array($encounter->stage, [EncounterStage::WAITING_TRIAGE, EncounterStage::WAITING_DOCTOR, EncounterStage::AWAITING_RESULTS]))
                        <flux:button variant="primary" icon="play" wire:click="startConsultation">{{ __('Start consultation') }}</flux:button>
                    @endif
                @endcan
                @can('encounters.update')
                    @if ($encounter->stage === EncounterStage::IN_CONSULTATION)
                        <flux:button size="sm" wire:click="moveTo('{{ EncounterStage::AWAITING_RESULTS }}')">{{ __('Await results') }}</flux:button>
                        <flux:button size="sm" wire:click="moveTo('{{ EncounterStage::WAITING_PHARMACY }}')">{{ __('Send to pharmacy') }}</flux:button>
                        <flux:button size="sm" wire:click="moveTo('{{ EncounterStage::WAITING_BILLING }}')">{{ __('Send to billing') }}</flux:button>
                    @endif
                @endcan
                @can('referrals.manage')
                    @if (\App\Support\ModuleRegistry::enabledAt(app(\App\Services\Tenancy\TenantContext::class)->facility(), 'referrals'))
                        <flux:button size="sm" icon="arrows-right-left" :href="route('referrals.create', ['direction' => 'outgoing', 'encounter' => $encounter->id])" wire:navigate>{{ __('Refer') }}</flux:button>
                    @endif
                @endcan
                @can('encounters.sign')
                    <flux:button size="sm" variant="filled" icon="check" wire:click="completeVisit" wire:confirm="{{ __('Close this visit? Signed notes and orders remain on the record.') }}">{{ __('Complete visit') }}</flux:button>
                @endcan
            @endif
        </div>
    </div>

    <x-patient-banner :patient="$this->patient" :encounter="$encounter" />

    @if ($v = $this->vitals->first())
        <div class="flex flex-wrap gap-4 rounded-xl border border-neutral-200 px-4 py-2 text-sm dark:border-neutral-700">
            <span><span class="text-neutral-500">BP</span> {{ $v->bloodPressure() ?? '—' }}</span>
            <span><span class="text-neutral-500">HR</span> {{ $v->pulse ?? '—' }}</span>
            <span><span class="text-neutral-500">RR</span> {{ $v->respiratory_rate ?? '—' }}</span>
            <span><span class="text-neutral-500">T</span> {{ $v->temperature ?? '—' }}°C</span>
            <span><span class="text-neutral-500">SpO₂</span> {{ $v->spo2 ?? '—' }}%</span>
            <span><span class="text-neutral-500">Wt</span> {{ $v->weight_kg ?? '—' }}kg</span>
            <span><span class="text-neutral-500">BMI</span> {{ $v->bmi ?? '—' }}</span>
            <span><span class="text-neutral-500">Pain</span> {{ $v->pain_score ?? '—' }}/10</span>
            <span class="text-neutral-500">{{ $v->recorded_at->format('d M H:i') }}</span>
        </div>
    @endif

    <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['notes' => __('Notes'), 'diagnosis' => __('Diagnosis & allergies'), 'orders' => __('Lab & imaging'), 'prescriptions' => __('Prescriptions'), 'disposition' => __('Admission'), 'timeline' => __('Timeline')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $key ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- NOTES --}}
    @if ($tab === 'notes')
        <div class="grid gap-6 xl:grid-cols-2">
            @if ($encounter->isOpen() && auth()->user()->can('clinical_notes.create'))
                <form wire:submit="saveNote" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">
                        {{ $amendingNoteId ? __('Amend signed note') : ($editingNoteId ? __('Edit draft') : __('New consultation note')) }}
                    </flux:heading>
                    <flux:textarea wire:model="note.presenting_complaint" rows="2" :label="__('Presenting complaint')" />
                    <flux:textarea wire:model="note.history" rows="3" :label="__('History of presenting illness')" />
                    <flux:textarea wire:model="note.examination" rows="3" :label="__('Examination')" />
                    <flux:textarea wire:model="note.assessment" rows="2" :label="__('Assessment')" />
                    <flux:textarea wire:model="note.plan" rows="2" :label="__('Plan')" />
                    @if ($amendingNoteId)
                        <flux:input wire:model="amendment_reason" :label="__('Reason for amendment')" required />
                    @endif
                    <div class="flex gap-2">
                        @if (! $amendingNoteId)
                            <flux:button type="submit">{{ __('Save draft') }}</flux:button>
                            @can('clinical_notes.sign')
                                <flux:button variant="primary" wire:click="saveNote(true)">{{ __('Save & sign') }}</flux:button>
                            @endcan
                        @else
                            <flux:button type="submit" variant="primary">{{ __('Save amendment') }}</flux:button>
                        @endif
                        @if ($editingNoteId || $amendingNoteId)
                            <flux:button variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                        @endif
                    </div>
                </form>
            @endif

            <div class="space-y-3">
                @forelse ($this->notes as $n)
                    <div wire:key="note-{{ $n->id }}" class="rounded-xl border p-4 text-sm {{ $n->status === 'amended' ? 'border-neutral-200 opacity-60 dark:border-neutral-700' : 'border-neutral-200 dark:border-neutral-700' }}">
                        <div class="mb-2 flex items-center justify-between">
                            <div>
                                <span class="font-medium">{{ $n->author?->name }}</span>
                                <span class="text-neutral-500">· {{ $n->created_at->format('d M Y H:i') }}</span>
                                @if ($n->amends_note_id) <flux:badge size="sm" color="purple">{{ __('Amendment') }}</flux:badge> @endif
                            </div>
                            <flux:badge size="sm" :color="['draft' => 'amber', 'signed' => 'lime', 'amended' => 'zinc'][$n->status]">{{ $n->status }}</flux:badge>
                        </div>
                        @foreach (['presenting_complaint' => __('Complaint'), 'history' => __('History'), 'examination' => __('Examination'), 'assessment' => __('Assessment'), 'plan' => __('Plan')] as $f => $l)
                            @if ($n->{$f})
                                <div class="mt-1"><span class="font-medium text-neutral-500">{{ $l }}:</span> {!! nl2br(e($n->{$f})) !!}</div>
                            @endif
                        @endforeach
                        @if ($n->amendment_reason)
                            <div class="mt-1 text-xs text-purple-600">{{ __('Amendment reason') }}: {{ $n->amendment_reason }}</div>
                        @endif
                        @if ($encounter->isOpen())
                            <div class="mt-2 flex gap-2">
                                @if ($n->status === 'draft' && $n->author_id === auth()->id())
                                    <flux:button size="xs" wire:click="editNote({{ $n->id }})">{{ __('Edit') }}</flux:button>
                                    @can('clinical_notes.sign')
                                        <flux:button size="xs" variant="primary" wire:click="signNote({{ $n->id }})">{{ __('Sign') }}</flux:button>
                                    @endcan
                                    <flux:button size="xs" variant="ghost" wire:click="discardDraft({{ $n->id }})" wire:confirm="{{ __('Discard this draft?') }}">{{ __('Discard') }}</flux:button>
                                @elseif ($n->status === 'signed')
                                    @can('clinical_notes.amend')
                                        <flux:button size="xs" variant="ghost" wire:click="startAmend({{ $n->id }})">{{ __('Amend') }}</flux:button>
                                    @endcan
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <flux:text>{{ __('No notes yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endif

    {{-- DIAGNOSIS --}}
    @if ($tab === 'diagnosis')
        <div class="grid gap-6 xl:grid-cols-2">
            <div class="space-y-3">
                <flux:heading level="3">{{ __('Diagnoses') }}</flux:heading>
                @forelse ($this->diagnoses as $d)
                    <div wire:key="dx-{{ $d->id }}" class="flex items-center justify-between rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div>
                            @if ($d->code)<span class="font-mono">{{ $d->code }}</span> — @endif{{ $d->description }}
                            <div class="text-xs text-neutral-500">{{ ucfirst($d->type) }} · {{ $d->certainty }}</div>
                        </div>
                        @if ($encounter->isOpen() && $d->recorded_by === auth()->id())
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeDiagnosis({{ $d->id }})" />
                        @endif
                    </div>
                @empty
                    <flux:text>{{ __('No diagnosis recorded.') }}</flux:text>
                @endforelse

                @if ($encounter->isOpen() && auth()->user()->can('clinical_notes.create'))
                    <form wire:submit="addDiagnosis" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <div>
                            <flux:input wire:model.live.debounce.250ms="dx_search" icon="magnifying-glass" :label="__('Search ICD-11')" :placeholder="__('Type a condition or code, e.g. malaria, pneumonia, BA00')" autocomplete="off" />
                            @if ($this->icdResults->isNotEmpty())
                                <div class="mt-1 max-h-60 overflow-y-auto rounded-lg border border-neutral-200 bg-white text-sm shadow-sm dark:border-neutral-700 dark:bg-zinc-900">
                                    @foreach ($this->icdResults as $icd)
                                        <button type="button" wire:key="icd-{{ $icd->id }}" wire:click="pickIcd({{ $icd->id }})" class="flex w-full items-center gap-3 px-3 py-1.5 text-left hover:bg-indigo-50 dark:hover:bg-zinc-800">
                                            <span class="w-16 shrink-0 font-mono text-xs font-semibold text-indigo-600">{{ $icd->code }}</span>
                                            <span class="truncate">{{ $icd->title }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif (mb_strlen(trim($dx_search)) >= 2)
                                <flux:text class="mt-1 text-xs">{{ __('No ICD-11 match. You can still record the diagnosis as free text (not claimable from SHA).') }}</flux:text>
                            @endif
                        </div>
                        <div class="grid gap-3 sm:grid-cols-4">
                            <flux:input wire:model="dx_code" :label="__('ICD-11 code')" placeholder="CA40.0" />
                            <div class="sm:col-span-3"><flux:input wire:model="dx_description" :label="__('Diagnosis')" required /></div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:select wire:model="dx_type" :label="__('Type')">
                                <flux:select.option value="primary">{{ __('Primary') }}</flux:select.option>
                                <flux:select.option value="secondary">{{ __('Secondary') }}</flux:select.option>
                                <flux:select.option value="differential">{{ __('Differential') }}</flux:select.option>
                            </flux:select>
                            <flux:select wire:model="dx_certainty" :label="__('Certainty')">
                                <flux:select.option value="confirmed">{{ __('Confirmed') }}</flux:select.option>
                                <flux:select.option value="provisional">{{ __('Provisional') }}</flux:select.option>
                            </flux:select>
                        </div>
                        <div><flux:button type="submit" size="sm" variant="primary">{{ __('Add diagnosis') }}</flux:button></div>
                    </form>
                @endif
            </div>

            <div class="space-y-3">
                <flux:heading level="3">{{ __('Allergies') }}</flux:heading>
                @forelse ($this->patient->allergies as $a)
                    <div wire:key="al-{{ $a->id }}" class="rounded-lg border border-red-200 p-3 text-sm dark:border-red-900">
                        <span class="font-medium text-red-600">{{ $a->allergen }}</span> — {{ $a->reaction ?? __('reaction not specified') }} <flux:badge size="sm">{{ $a->severity }}</flux:badge>
                    </div>
                @empty
                    <flux:text>{{ __('No known allergies recorded.') }}</flux:text>
                @endforelse
                @can('clinical_notes.create')
                    <form wire:submit="addAllergy" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:input wire:model="allergen" :label="__('Allergen')" required />
                            <flux:input wire:model="allergy_reaction" :label="__('Reaction')" />
                            <flux:select wire:model="allergy_severity" :label="__('Severity')">
                                <flux:select.option value="mild">{{ __('Mild') }}</flux:select.option>
                                <flux:select.option value="moderate">{{ __('Moderate') }}</flux:select.option>
                                <flux:select.option value="severe">{{ __('Severe') }}</flux:select.option>
                            </flux:select>
                        </div>
                        <div><flux:button type="submit" size="sm">{{ __('Record allergy') }}</flux:button></div>
                    </form>
                @endcan
            </div>
        </div>
    @endif

    {{-- ORDERS --}}
    @if ($tab === 'orders')
        <div class="grid gap-6 xl:grid-cols-2">
            @if ($encounter->isOpen() && auth()->user()->can('orders.create'))
                <div class="space-y-4">
                    <form wire:submit="orderLab" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <flux:heading level="3">{{ __('Laboratory request') }}</flux:heading>
                        <flux:input wire:model.live.debounce.300ms="labSearch" icon="magnifying-glass" size="sm" :placeholder="__('Filter tests')" />
                        <div class="grid max-h-56 gap-1 overflow-y-auto sm:grid-cols-2">
                            @foreach ($this->labTests as $t)
                                <label wire:key="lt-{{ $t->id }}" class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                    <input type="checkbox" wire:model="labTestIds" value="{{ $t->id }}" class="rounded">
                                    <span>{{ $t->name }} <span class="text-xs text-neutral-500">{{ $t->category }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        @error('labTestIds') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:select wire:model="labPriority" :label="__('Priority')">
                                <flux:select.option value="routine">{{ __('Routine') }}</flux:select.option>
                                <flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option>
                                <flux:select.option value="stat">{{ __('STAT') }}</flux:select.option>
                            </flux:select>
                            <div class="sm:col-span-2"><flux:input wire:model="labNotes" :label="__('Clinical notes')" /></div>
                        </div>
                        <div><flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">{{ __('Send to laboratory') }}</flux:button></div>
                    </form>

                    <form wire:submit="orderRadiology" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <flux:heading level="3">{{ __('Imaging request') }}</flux:heading>
                        <flux:select wire:model="radiologyExamId" :label="__('Examination')">
                            <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                            @foreach ($this->radiologyExams as $x)
                                <flux:select.option value="{{ $x->id }}">{{ strtoupper($x->modality) }} — {{ $x->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:select wire:model="radPriority" :label="__('Priority')">
                                <flux:select.option value="routine">{{ __('Routine') }}</flux:select.option>
                                <flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option>
                                <flux:select.option value="stat">{{ __('STAT') }}</flux:select.option>
                            </flux:select>
                            <div class="sm:col-span-2"><flux:input wire:model="radIndication" :label="__('Clinical indication')" /></div>
                        </div>
                        <div><flux:button type="submit" variant="primary" size="sm">{{ __('Send to radiology') }}</flux:button></div>
                    </form>
                </div>
            @endif

            <div class="space-y-3">
                <flux:heading level="3">{{ __('Results') }}</flux:heading>
                @forelse ($this->labOrders as $o)
                    <div wire:key="lo-{{ $o->id }}" class="rounded-xl border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="mb-2 flex justify-between text-xs text-neutral-500"><span class="font-mono">{{ $o->order_number }}</span><span>{{ $o->priority }} · {{ $o->created_at->format('d M H:i') }}</span></div>
                        <table class="w-full">
                            @foreach ($o->items as $i)
                                <tr wire:key="loi-{{ $i->id }}" class="{{ $i->flag === 'critical' ? 'text-red-600 font-semibold' : '' }}">
                                    <td class="py-0.5">{{ $i->test->name }}</td>
                                    <td class="py-0.5">
                                        @if ($i->status === 'released')
                                            {{ $i->result_value }} {{ $i->result_unit }}
                                            @if ($i->flag && $i->flag !== 'normal') <flux:badge size="sm" :color="$i->flag === 'critical' ? 'red' : 'amber'">{{ $i->flag }}</flux:badge> @endif
                                        @else
                                            <flux:badge size="sm">{{ $i->status }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="py-0.5 text-xs text-neutral-500">{{ $i->reference_range }}</td>
                                </tr>
                                @if ($i->status === 'released' && $i->comments)
                                    <tr><td colspan="3" class="pb-1 text-xs text-neutral-500">{{ $i->comments }}</td></tr>
                                @endif
                            @endforeach
                        </table>
                    </div>
                @empty
                @endforelse

                @foreach ($this->radiologyOrders as $r)
                    <div wire:key="ro-{{ $r->id }}" class="rounded-xl border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="flex justify-between"><span class="font-medium">{{ $r->exam->name }}</span><flux:badge size="sm">{{ $r->status }}</flux:badge></div>
                        <div class="text-xs text-neutral-500">{{ $r->order_number }} · {{ $r->clinical_indication }}</div>
                        @if ($r->status === 'verified')
                            <div class="mt-2"><span class="font-medium">{{ __('Findings') }}:</span> {!! nl2br(e($r->findings)) !!}</div>
                            <div class="mt-1"><span class="font-medium">{{ __('Impression') }}:</span> {!! nl2br(e($r->impression)) !!}</div>
                        @endif
                    </div>
                @endforeach

                @if ($this->labOrders->isEmpty() && $this->radiologyOrders->isEmpty())
                    <flux:text>{{ __('No investigations requested.') }}</flux:text>
                @endif
            </div>
        </div>
    @endif

    {{-- PRESCRIPTIONS --}}
    @if ($tab === 'prescriptions')
        <div class="grid gap-6 xl:grid-cols-2">
            @if ($encounter->isOpen() && auth()->user()->can('orders.create'))
                <form wire:submit="prescribe" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('New prescription') }}</flux:heading>
                    <div>
                        <flux:input wire:model.live.debounce.300ms="medSearch" icon="magnifying-glass" :placeholder="__('Search medicine by generic or brand name')" />
                        @foreach ($this->medicineMatches as $m)
                            <button type="button" wire:key="mm-{{ $m->id }}" wire:click="addMedicine({{ $m->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
                                {{ $m->displayName() }} @if ($m->brand_name) <span class="text-neutral-500">({{ $m->brand_name }})</span> @endif
                            </button>
                        @endforeach
                    </div>
                    @foreach ($rxItems as $i => $item)
                        <div wire:key="rx-{{ $i }}" class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                            <div class="mb-2 flex items-center justify-between text-sm">
                                <span class="font-medium">{{ $item['name'] }}</span>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs {{ $item['stock'] < $item['quantity'] ? 'text-red-600' : 'text-neutral-500' }}">{{ __('In stock') }}: {{ $item['stock'] }}</span>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeRxItem({{ $i }})" />
                                </div>
                            </div>
                            @if ($item['allergy'])
                                <flux:callout variant="danger" icon="exclamation-triangle" class="mb-2" :heading="__('Allergy warning: patient is allergic to :a', ['a' => $item['allergy']])" />
                            @endif
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <flux:input size="sm" wire:model.live.debounce.400ms="rxItems.{{ $i }}.dose" :label="__('Dose')" />
                                <flux:select size="sm" wire:model.live="rxItems.{{ $i }}.frequency" :label="__('Frequency')">
                                    @foreach (['OD', 'BD', 'TDS', 'QID', 'NOCTE', 'STAT', 'PRN'] as $f)
                                        <flux:select.option value="{{ $f }}">{{ $f }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:input size="sm" wire:model="rxItems.{{ $i }}.route" :label="__('Route')" />
                                <flux:input size="sm" type="number" wire:model.live.debounce.400ms="rxItems.{{ $i }}.duration_days" :label="__('Days')" />
                                <flux:input size="sm" type="number" wire:model="rxItems.{{ $i }}.quantity" :label="__('Qty')" />
                            </div>
                            <flux:input size="sm" class="mt-2" wire:model="rxItems.{{ $i }}.instructions" :placeholder="__('Instructions, e.g. after meals')" />
                        </div>
                    @endforeach
                    @error('rxItems') <flux:text class="text-sm text-red-500">{{ __('Add at least one medicine.') }}</flux:text> @enderror
                    <flux:input wire:model="rxNotes" :label="__('Notes to pharmacist')" />
                    <div><flux:button type="submit" variant="primary" size="sm" :disabled="empty($rxItems)">{{ __('Send prescription') }}</flux:button></div>
                </form>
            @endif

            <div class="space-y-3">
                @forelse ($this->prescriptions as $rx)
                    <div wire:key="prx-{{ $rx->id }}" class="rounded-xl border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="mb-2 flex justify-between"><span class="font-mono text-xs">{{ $rx->prescription_number }}</span><flux:badge size="sm" :color="$rx->status === 'dispensed' ? 'lime' : ($rx->status === 'cancelled' ? 'zinc' : 'amber')">{{ str_replace('_', ' ', $rx->status) }}</flux:badge></div>
                        @foreach ($rx->items as $it)
                            <div wire:key="prxi-{{ $it->id }}">{{ $it->medicine->displayName() }} — {{ $it->dose }} {{ $it->frequency }} {{ $it->route }} × {{ $it->duration_days }}d <span class="text-neutral-500">({{ $it->quantity_dispensed }}/{{ $it->quantity }} {{ __('dispensed') }})</span></div>
                        @endforeach
                        <div class="mt-1 text-xs text-neutral-500">{{ $rx->prescriber?->name }} · {{ $rx->created_at->format('d M H:i') }}</div>
                    </div>
                @empty
                    <flux:text>{{ __('No prescriptions.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endif

    {{-- DISPOSITION --}}
    @if ($tab === 'disposition')
        <div class="grid gap-6 xl:grid-cols-2">
            @if ($encounter->isOpen() && auth()->user()->can('wards.admissions.create') && ! $this->admissions->whereIn('status', ['requested', 'admitted'])->count())
                <form wire:submit="requestAdmission" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Request admission') }}</flux:heading>
                    <flux:select wire:model="admit_ward_id" :label="__('Preferred ward')">
                        <flux:select.option value="">{{ __('Any') }}</flux:select.option>
                        @foreach ($this->wards as $w)
                            <flux:select.option value="{{ $w->id }}">{{ $w->name }} ({{ $w->available_beds }} {{ __('free') }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="admit_type" :label="__('Admission type')">
                        <flux:select.option value="planned">{{ __('Planned') }}</flux:select.option>
                        <flux:select.option value="emergency">{{ __('Emergency') }}</flux:select.option>
                        <flux:select.option value="direct">{{ __('Direct') }}</flux:select.option>
                    </flux:select>
                    <flux:textarea wire:model="admit_reason" rows="3" :label="__('Reason / working diagnosis')" required />
                    <div><flux:button type="submit" variant="primary" size="sm">{{ __('Request admission') }}</flux:button></div>
                </form>
            @endif
            <div class="space-y-3">
                @forelse ($this->admissions as $a)
                    <div wire:key="adm-{{ $a->id }}" class="rounded-xl border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="flex justify-between"><span class="font-mono text-xs">{{ $a->admission_number }}</span><flux:badge size="sm">{{ $a->status }}</flux:badge></div>
                        <div>{{ $a->ward?->name ?? __('Ward not yet assigned') }} @if ($a->bed) · {{ __('Bed') }} {{ $a->bed->bed_number }} @endif</div>
                        <div class="text-xs text-neutral-500">{{ $a->reason }}</div>
                        @if (in_array($a->status, ['admitted', 'discharged']) && auth()->user()->canAny(['wards.view', 'nursing.tasks.manage']))
                            <flux:button size="xs" class="mt-2" :href="route('wards.admission', $a)" wire:navigate>{{ __('Open inpatient chart') }}</flux:button>
                        @endif
                    </div>
                @empty
                    <flux:text>{{ __('No admission for this visit.') }}</flux:text>
                @endforelse
                @can('theatre.schedule')
                    @if ($encounter->isOpen())
                        <flux:button size="sm" icon="scissors" :href="route('theatre.index', ['encounter' => $encounter->id])" wire:navigate>{{ __('Book theatre') }}</flux:button>
                    @endif
                @endcan
            </div>
        </div>
    @endif

    {{-- TIMELINE --}}
    @if ($tab === 'timeline')
        <div class="space-y-2">
            @foreach ($this->transitions as $t)
                <div wire:key="tr-{{ $t->id }}" class="flex items-center gap-3 text-sm">
                    <span class="w-32 text-neutral-500">{{ $t->created_at->format('d M H:i') }}</span>
                    <flux:badge size="sm" :color="EncounterStage::color($t->to_stage)">{{ EncounterStage::label($t->to_stage) }}</flux:badge>
                    <span>{{ $t->reason }}</span>
                    <span class="text-neutral-500">— {{ $t->actor?->name ?? __('system') }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
