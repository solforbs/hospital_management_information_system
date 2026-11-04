<?php

use App\Models\Admission;
use App\Services\Inpatient\CriticalCareService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Critical-care chart')] class extends Component {
    public Admission $admission;

    public array $obs = [];

    public function mount(Admission $admission): void
    {
        abort_unless(Auth::user()->can('critical_care.chart'), 403);
        $this->admission = $admission->load(['patient', 'ward', 'bed', 'doctor']);
        abort_unless(CriticalCareService::isCriticalCare($this->admission) || $this->admission->criticalCareObservations()->exists(), 404);
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->obs = array_fill_keys(['heart_rate', 'systolic', 'diastolic', 'map', 'resp_rate', 'spo2', 'temperature', 'gcs', 'fio2', 'ventilation', 'peep', 'blood_glucose', 'intake_ml', 'output_ml', 'urine_ml', 'sedation_score', 'pain_score', 'notes'], '');
        $this->obs['recorded_at'] = now()->format('Y-m-d\TH:i');
    }

    #[Computed]
    public function entries()
    {
        return $this->admission->criticalCareObservations()->with('recorder')->where('recorded_at', '>=', now()->subHours(48))->latest('recorded_at')->get();
    }

    #[Computed]
    public function balance(): array
    {
        return app(CriticalCareService::class)->fluidBalance($this->admission);
    }

    public function record(CriticalCareService $service): void
    {
        abort_unless(Auth::user()->can('critical_care.chart'), 403);

        $data = $this->validate([
            'obs.recorded_at' => ['required', 'date'],
            'obs.heart_rate' => ['nullable', 'integer', 'between:0,300'],
            'obs.systolic' => ['nullable', 'integer', 'between:0,300'],
            'obs.diastolic' => ['nullable', 'integer', 'between:0,200'],
            'obs.map' => ['nullable', 'integer', 'between:0,250'],
            'obs.resp_rate' => ['nullable', 'integer', 'between:0,80'],
            'obs.spo2' => ['nullable', 'integer', 'between:0,100'],
            'obs.temperature' => ['nullable', 'numeric', 'between:25,45'],
            'obs.gcs' => ['nullable', 'integer', 'between:3,15'],
            'obs.fio2' => ['nullable', 'integer', 'between:21,100'],
            'obs.ventilation' => ['nullable', 'in:room_air,oxygen,niv,invasive'],
            'obs.peep' => ['nullable', 'integer', 'between:0,30'],
            'obs.blood_glucose' => ['nullable', 'numeric', 'between:0,60'],
            'obs.intake_ml' => ['nullable', 'integer', 'between:0,10000'],
            'obs.output_ml' => ['nullable', 'integer', 'between:0,10000'],
            'obs.urine_ml' => ['nullable', 'integer', 'between:0,10000'],
            'obs.sedation_score' => ['nullable', 'in:-5,-4,-3,-2,-1,0,1,2,3,4'],
            'obs.pain_score' => ['nullable', 'integer', 'between:0,10'],
            'obs.notes' => ['nullable', 'string', 'max:1000'],
        ])['obs'];

        $filled = collect($data)->except(['recorded_at', 'notes'])->filter(fn ($v) => $v !== '' && $v !== null);
        if ($filled->isEmpty()) {
            $this->addError('obs', __('Enter at least one observation.'));

            return;
        }

        if (($data['urine_ml'] ?? '') !== '' && ($data['output_ml'] ?? '') !== '' && (int) $data['urine_ml'] > (int) $data['output_ml']) {
            $this->addError('obs.urine_ml', __('Urine is part of total output and cannot exceed it.'));

            return;
        }

        $o = $service->record($this->admission, $data);
        $this->resetForm();
        unset($this->entries, $this->balance);

        $o->alerts()
            ? Flux::toast(variant: 'danger', text: __('Abnormal values recorded — the admitting doctor has been alerted.'))
            : Flux::toast(variant: 'success', text: __('Observations recorded.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Critical-care chart') }}</flux:heading>
            <flux:text>{{ $admission->ward?->name }} · {{ __('bed') }} {{ $admission->bed?->bed_number }} · {{ __('day') }} {{ $admission->lengthOfStayDays() }} · {{ $admission->doctor?->name ?? '—' }}</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button size="sm" :href="route('wards.admission', $admission)" wire:navigate>{{ __('Inpatient chart') }}</flux:button>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('critical-care.index')" wire:navigate>{{ __('Critical care') }}</flux:button>
        </div>
    </div>

    <x-patient-banner :patient="$admission->patient" />

    <div class="grid gap-3 sm:grid-cols-4">
        @foreach ([__('Intake (24h)') => $this->balance['intake'].' ml', __('Output (24h)') => $this->balance['output'].' ml', __('Urine (24h)') => $this->balance['urine'].' ml', __('Balance (24h)') => ($this->balance['balance'] > 0 ? '+' : '').$this->balance['balance'].' ml'] as $label => $value)
            <div class="rounded-xl border border-neutral-200 p-3 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ $label }}</div><div class="text-lg font-semibold">{{ $value }}</div></div>
        @endforeach
    </div>

    @if ($admission->status === 'admitted')
        <form wire:submit="record" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="grid gap-3 sm:grid-cols-6">
                <flux:input type="datetime-local" wire:model="obs.recorded_at" :label="__('Time')" class="sm:col-span-2" />
                <flux:input wire:model="obs.heart_rate" type="number" :label="__('HR')" />
                <flux:input wire:model="obs.systolic" type="number" :label="__('Systolic')" />
                <flux:input wire:model="obs.diastolic" type="number" :label="__('Diastolic')" />
                <flux:input wire:model="obs.map" type="number" :label="__('MAP (auto)')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-6">
                <flux:input wire:model="obs.resp_rate" type="number" :label="__('RR')" />
                <flux:input wire:model="obs.spo2" type="number" :label="__('SpO₂ %')" />
                <flux:input wire:model="obs.temperature" type="number" step="0.1" :label="__('Temp °C')" />
                <flux:input wire:model="obs.gcs" type="number" :label="__('GCS')" />
                <flux:input wire:model="obs.blood_glucose" type="number" step="0.1" :label="__('Glucose mmol/L')" />
                <flux:input wire:model="obs.pain_score" type="number" :label="__('Pain 0–10')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-6">
                <flux:select wire:model="obs.ventilation" :label="__('Resp. support')" class="sm:col-span-2">
                    <flux:select.option value="">—</flux:select.option>
                    <flux:select.option value="room_air">{{ __('Room air') }}</flux:select.option>
                    <flux:select.option value="oxygen">{{ __('Oxygen (mask / nasal)') }}</flux:select.option>
                    <flux:select.option value="niv">{{ __('Non-invasive (CPAP/BiPAP)') }}</flux:select.option>
                    <flux:select.option value="invasive">{{ __('Invasive ventilation') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="obs.fio2" type="number" :label="__('FiO₂ %')" />
                <flux:input wire:model="obs.peep" type="number" :label="__('PEEP')" />
                <flux:select wire:model="obs.sedation_score" :label="__('RASS')" class="sm:col-span-2">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach (range(4, -5) as $s)
                        <flux:select.option value="{{ $s }}">{{ $s > 0 ? '+'.$s : $s }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-3 sm:grid-cols-6">
                <flux:input wire:model="obs.intake_ml" type="number" :label="__('Intake ml')" />
                <flux:input wire:model="obs.output_ml" type="number" :label="__('Total output ml')" />
                <flux:input wire:model="obs.urine_ml" type="number" :label="__('Of which urine ml')" />
                <div class="sm:col-span-3"><flux:input wire:model="obs.notes" :label="__('Notes')" /></div>
            </div>
            @error('obs')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            <div><flux:button type="submit" variant="primary">{{ __('Record') }}</flux:button></div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-xs">
            <thead class="bg-neutral-50 uppercase text-neutral-500 dark:bg-neutral-900">
                <tr>@foreach (['Time', 'HR', 'BP', 'MAP', 'RR', 'SpO₂', 'Temp', 'GCS', 'Support', 'FiO₂/PEEP', 'Gluc', 'In', 'Out', 'Urine', 'RASS', 'By'] as $h)<th class="px-2 py-2">{{ __($h) }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->entries as $e)
                    @php($bad = $e->alerts())
                    <tr wire:key="o-{{ $e->id }}">
                        <td class="px-2 py-1 whitespace-nowrap">{{ $e->recorded_at->format('d M H:i') }}</td>
                        <td class="px-2 py-1 {{ in_array('heart_rate', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->heart_rate }}</td>
                        <td class="px-2 py-1 {{ in_array('systolic', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->systolic }}@if ($e->diastolic)/{{ $e->diastolic }}@endif</td>
                        <td class="px-2 py-1 {{ in_array('map', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->map }}</td>
                        <td class="px-2 py-1 {{ in_array('resp_rate', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->resp_rate }}</td>
                        <td class="px-2 py-1 {{ in_array('spo2', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->spo2 }}</td>
                        <td class="px-2 py-1 {{ in_array('temperature', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->temperature }}</td>
                        <td class="px-2 py-1 {{ in_array('gcs', $bad) ? 'font-bold text-red-600' : '' }}">{{ $e->gcs }}</td>
                        <td class="px-2 py-1">{{ $e->ventilation ? str_replace('_', ' ', $e->ventilation) : '' }}</td>
                        <td class="px-2 py-1">{{ $e->fio2 }}@if ($e->peep)/{{ $e->peep }}@endif</td>
                        <td class="px-2 py-1">{{ $e->blood_glucose }}</td>
                        <td class="px-2 py-1">{{ $e->intake_ml }}</td>
                        <td class="px-2 py-1">{{ $e->output_ml }}</td>
                        <td class="px-2 py-1">{{ $e->urine_ml }}</td>
                        <td class="px-2 py-1">{{ $e->sedation_score }}</td>
                        <td class="px-2 py-1 whitespace-nowrap">{{ $e->recorder?->name }}@if ($e->notes)<div class="text-neutral-500">{{ $e->notes }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="16" class="px-3 py-6 text-center text-neutral-500">{{ __('No observations in the last 48 hours.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
