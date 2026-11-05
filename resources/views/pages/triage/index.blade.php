<?php

use App\Models\Encounter;
use App\Services\Clinical\TriageService;
use App\Support\EncounterStage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Triage')] class extends Component {
    public ?int $encounterId = null;

    public array $vitals = [];

    public string $triage_category = '';

    public string $chief_complaint = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('triage.record'), 403);
        $this->resetVitals();
    }

    protected function resetVitals(): void
    {
        $this->vitals = array_fill_keys(['temperature', 'pulse', 'respiratory_rate', 'systolic_bp', 'diastolic_bp', 'spo2', 'weight_kg', 'height_cm', 'pain_score', 'blood_glucose', 'notes'], '');
        $this->triage_category = '';
        $this->chief_complaint = '';
    }

    #[Computed]
    public function queue()
    {
        return Encounter::with('patient')
            ->where('status', 'open')
            ->where('stage', EncounterStage::WAITING_TRIAGE)
            ->orderByRaw("case priority when 'emergency' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('started_at')
            ->get();
    }

    #[Computed]
    public function encounter()
    {
        return $this->encounterId ? Encounter::with(['patient.allergies'])->find($this->encounterId) : null;
    }

    public function select(int $id): void
    {
        $this->encounterId = Encounter::where('status', 'open')->findOrFail($id)->id;
        $this->resetVitals();
        $this->chief_complaint = (string) $this->encounter->chief_complaint;
    }

    public function save(TriageService $triage): void
    {
        abort_unless(Auth::user()->can('triage.record'), 403);

        $this->validate([
            'vitals.temperature' => ['nullable', 'numeric', 'between:25,45'],
            'vitals.pulse' => ['nullable', 'integer', 'between:20,250'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'vitals.systolic_bp' => ['nullable', 'integer', 'between:40,300', 'required_with:vitals.diastolic_bp'],
            'vitals.diastolic_bp' => ['nullable', 'integer', 'between:20,200', 'required_with:vitals.systolic_bp'],
            'vitals.spo2' => ['nullable', 'integer', 'between:40,100'],
            'vitals.weight_kg' => ['nullable', 'numeric', 'between:0.3,400'],
            'vitals.height_cm' => ['nullable', 'numeric', 'between:20,250'],
            'vitals.pain_score' => ['nullable', 'integer', 'between:0,10'],
            'vitals.blood_glucose' => ['nullable', 'numeric', 'between:0.5,60'],
            'vitals.notes' => ['nullable', 'string', 'max:255'],
            'triage_category' => ['nullable', 'in:red,orange,yellow,green,blue'],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
        ]);

        $encounter = Encounter::where('status', 'open')->findOrFail($this->encounterId);

        $record = $triage->record($encounter, $this->vitals, $this->triage_category ?: null, $this->chief_complaint ?: null);

        Flux::toast(variant: 'success', text: __(':name triaged (:category) and sent to the doctor queue.', ['name' => $encounter->patient->full_name, 'category' => $encounter->fresh()->triage_category]));

        $this->encounterId = null;
        $this->resetVitals();
        unset($this->queue);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="queue,triage">
    <flux:heading level="1">{{ __('Triage') }}</flux:heading>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-2">
            <flux:heading level="3">{{ __('Waiting for triage') }} ({{ $this->queue->count() }})</flux:heading>
            @forelse ($this->queue as $e)
                <button type="button" wire:key="t-{{ $e->id }}" wire:click="select({{ $e->id }})" class="block w-full rounded-lg border p-3 text-left text-sm {{ $encounterId === $e->id ? 'border-indigo-500' : 'border-neutral-200 dark:border-neutral-700' }} {{ $e->priority !== 'normal' ? 'bg-red-50 dark:bg-red-950/40' : '' }}">
                    <div class="flex justify-between"><span class="font-medium">#{{ $e->queue_number }} {{ $e->patient->full_name }}</span><span class="text-xs text-neutral-500">{{ $e->started_at->format('H:i') }}</span></div>
                    <div class="text-xs text-neutral-500">{{ $e->patient->mrn }} &middot; {{ ucfirst($e->patient->sex) }} {{ $e->patient->age }} &middot; {{ $e->chief_complaint }}</div>
                </button>
            @empty
                <flux:text>{{ __('Nobody waiting.') }}</flux:text>
            @endforelse
        </div>

        <div class="lg:col-span-2">
            @if ($this->encounter)
                <x-patient-banner :patient="$this->encounter->patient" :encounter="$this->encounter" class="mb-4" />
                <form wire:submit="save" class="grid gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                    <flux:input wire:model="chief_complaint" :label="__('Chief complaint')" />
                    <div class="grid gap-3 sm:grid-cols-4">
                        <flux:input wire:model="vitals.temperature" type="number" step="0.1" :label="__('Temp (°C)')" />
                        <flux:input wire:model="vitals.pulse" type="number" :label="__('Pulse (bpm)')" />
                        <flux:input wire:model="vitals.respiratory_rate" type="number" :label="__('Resp. rate')" />
                        <flux:input wire:model="vitals.spo2" type="number" :label="__('SpO₂ (%)')" />
                        <flux:input wire:model="vitals.systolic_bp" type="number" :label="__('Systolic BP')" />
                        <flux:input wire:model="vitals.diastolic_bp" type="number" :label="__('Diastolic BP')" />
                        <flux:input wire:model="vitals.weight_kg" type="number" step="0.1" :label="__('Weight (kg)')" />
                        <flux:input wire:model="vitals.height_cm" type="number" step="0.1" :label="__('Height (cm)')" />
                        <flux:input wire:model="vitals.pain_score" type="number" min="0" max="10" :label="__('Pain (0-10)')" />
                        <flux:input wire:model="vitals.blood_glucose" type="number" step="0.1" :label="__('Glucose (mmol/L)')" />
                    </div>
                    <flux:input wire:model="vitals.notes" :label="__('Notes')" />
                    <flux:select wire:model="triage_category" :label="__('Triage category')" :description="__('Leave blank to use the suggested category from the vital signs.')">
                        <flux:select.option value="">{{ __('Auto-suggest from vitals') }}</flux:select.option>
                        <flux:select.option value="red">{{ __('Red — immediate') }}</flux:select.option>
                        <flux:select.option value="orange">{{ __('Orange — very urgent') }}</flux:select.option>
                        <flux:select.option value="yellow">{{ __('Yellow — urgent') }}</flux:select.option>
                        <flux:select.option value="green">{{ __('Green — standard') }}</flux:select.option>
                        <flux:select.option value="blue">{{ __('Blue — non-urgent') }}</flux:select.option>
                    </flux:select>
                    <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Save & send to doctor') }}</flux:button></div>
                </form>
            @else
                <div class="rounded-xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-neutral-700">{{ __('Select a patient from the queue.') }}</div>
            @endif
        </div>
    </div>
</div>
