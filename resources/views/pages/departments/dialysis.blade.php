<?php

use App\Models\DialysisSession;
use App\Models\Patient;
use App\Services\Clinical\DepartmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Renal / dialysis')] class extends Component {
    public ?int $patientId = null;

    public string $patientSearch = '';

    public array $start = ['modality' => 'hd', 'machine' => '', 'access' => 'av_fistula', 'pre_weight_kg' => '', 'pre_systolic' => '', 'pre_diastolic' => '', 'uf_goal_ml' => '', 'heparin' => '', 'payer_type' => 'cash'];

    public ?int $endingId = null;

    public array $end = ['post_weight_kg' => '', 'post_systolic' => '', 'post_diastolic' => '', 'uf_achieved_ml' => '', 'complications' => ''];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('dialysis.manage'), 403);
    }

    #[Computed]
    public function matches()
    {
        if (mb_strlen($this->patientSearch) < 2) {
            return collect();
        }
        $t = '%'.$this->patientSearch.'%';

        return Patient::whereNull('deceased_at')->where(fn ($q) => $q->where('first_name', 'like', $t)->orWhere('last_name', 'like', $t)->orWhere('mrn', 'like', $t))->limit(8)->get();
    }

    #[Computed]
    public function active()
    {
        return DialysisSession::with('patient')->where('status', 'in_progress')->orderBy('started_at')->get();
    }

    #[Computed]
    public function recent()
    {
        return DialysisSession::with('patient')->where('status', '!=', 'in_progress')->latest('ended_at')->limit(20)->get();
    }

    public function startSession(DepartmentService $dept): void
    {
        $this->validate([
            'start.modality' => ['required', 'in:hd,hdf,pd'],
            'start.machine' => ['nullable', 'string', 'max:40'],
            'start.access' => ['nullable', 'in:av_fistula,catheter,graft'],
            'start.pre_weight_kg' => ['nullable', 'numeric', 'between:10,250'],
            'start.pre_systolic' => ['nullable', 'integer', 'between:50,260'],
            'start.pre_diastolic' => ['nullable', 'integer', 'between:30,180'],
            'start.uf_goal_ml' => ['nullable', 'integer', 'between:0,8000'],
            'start.heparin' => ['nullable', 'string', 'max:60'],
            'start.payer_type' => ['required', 'in:cash,insurance,corporate,waiver'],
        ]);
        abort_unless($this->patientId, 422);
        $dept->startDialysis(Patient::findOrFail($this->patientId), $this->start);
        $this->reset(['patientId', 'patientSearch']);
        unset($this->active);
        Flux::toast(variant: 'success', text: __('Session started.'));
    }

    public function endSession(bool $aborted = false): void
    {
        $dept = app(DepartmentService::class);
        $this->validate([
            'end.post_weight_kg' => ['nullable', 'numeric', 'between:10,250'],
            'end.post_systolic' => ['nullable', 'integer', 'between:50,260'],
            'end.post_diastolic' => ['nullable', 'integer', 'between:30,180'],
            'end.uf_achieved_ml' => ['nullable', 'integer', 'between:0,8000'],
            'end.complications' => ['nullable', 'string', 'max:1000'],
        ]);
        $dept->endDialysis(DialysisSession::findOrFail($this->endingId), $this->end, $aborted);
        $this->reset(['endingId', 'end']);
        unset($this->active, $this->recent);
        Flux::toast(variant: 'success', text: __('Session closed and billed.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Renal / dialysis') }}</flux:heading>

    <div class="grid gap-6 xl:grid-cols-5">
        <form wire:submit="startSession" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-5 xl:col-span-2 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Start session') }}</flux:heading>
            @if ($patientId)
                <div class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800"><span>{{ \App\Models\Patient::find($patientId)?->full_name }}</span><flux:button size="xs" variant="ghost" wire:click="$set('patientId', null)">{{ __('Change') }}</flux:button></div>
            @else
                <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Patient')" :placeholder="__('Name or MRN')" />
                @foreach ($this->matches as $p)<button type="button" wire:key="m-{{ $p->id }}" wire:click="$set('patientId', {{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $p->full_name }} <span class="font-mono text-xs">{{ $p->mrn }}</span></button>@endforeach
            @endif
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:select wire:model="start.modality" :label="__('Modality')"><flux:select.option value="hd">HD</flux:select.option><flux:select.option value="hdf">HDF</flux:select.option><flux:select.option value="pd">PD</flux:select.option></flux:select>
                <flux:select wire:model="start.access" :label="__('Access')"><flux:select.option value="av_fistula">{{ __('AV fistula') }}</flux:select.option><flux:select.option value="catheter">{{ __('Catheter') }}</flux:select.option><flux:select.option value="graft">{{ __('Graft') }}</flux:select.option></flux:select>
                <flux:input wire:model="start.machine" :label="__('Machine')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input type="number" step="0.1" wire:model="start.pre_weight_kg" :label="__('Pre wt kg')" />
                <flux:input type="number" wire:model="start.pre_systolic" :label="__('Systolic')" />
                <flux:input type="number" wire:model="start.pre_diastolic" :label="__('Diastolic')" />
                <flux:input type="number" wire:model="start.uf_goal_ml" :label="__('UF goal ml')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="start.heparin" :label="__('Anticoagulation')" />
                <flux:select wire:model="start.payer_type" :label="__('Payer')"><flux:select.option value="cash">{{ __('Cash') }}</flux:select.option><flux:select.option value="insurance">{{ __('SHA / insurance') }}</flux:select.option><flux:select.option value="corporate">{{ __('Corporate') }}</flux:select.option><flux:select.option value="waiver">{{ __('Waiver') }}</flux:select.option></flux:select>
            </div>
            @error('dialysis')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            <div><flux:button type="submit" variant="primary" :disabled="! $patientId">{{ __('Start') }}</flux:button></div>
        </form>

        <div class="space-y-4 xl:col-span-3">
            <flux:heading level="3">{{ __('On dialysis now') }} ({{ $this->active->count() }})</flux:heading>
            @forelse ($this->active as $s)
                <div wire:key="s-{{ $s->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                    <div><span class="font-medium">{{ $s->patient->full_name }}</span> <span class="font-mono text-xs text-zinc-500">{{ $s->session_number }}</span>
                        <div class="text-xs text-zinc-500">{{ strtoupper($s->modality) }} · {{ $s->machine ?? '—' }} · {{ __('since') }} {{ $s->started_at->format('H:i') }} ({{ $s->started_at->diffForHumans(null, true) }}) · {{ __('pre') }} {{ $s->pre_weight_kg ?? '—' }} kg · UF {{ $s->uf_goal_ml ?? '—' }} ml</div></div>
                    <flux:button size="sm" variant="primary" wire:click="$set('endingId', {{ $s->id }})">{{ __('End session') }}</flux:button>
                </div>
            @empty
                <flux:text>{{ __('No sessions in progress.') }}</flux:text>
            @endforelse

            @if ($endingId)
                <form wire:submit="endSession" class="grid gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                    <flux:heading level="3">{{ __('End session') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <flux:input type="number" step="0.1" wire:model="end.post_weight_kg" :label="__('Post wt kg')" />
                        <flux:input type="number" wire:model="end.post_systolic" :label="__('Systolic')" />
                        <flux:input type="number" wire:model="end.post_diastolic" :label="__('Diastolic')" />
                        <flux:input type="number" wire:model="end.uf_achieved_ml" :label="__('UF achieved ml')" />
                    </div>
                    <flux:input wire:model="end.complications" :label="__('Complications (hypotension, cramps, clotting...)')" />
                    <div class="flex gap-2"><flux:button type="submit" size="sm" variant="primary">{{ __('Complete') }}</flux:button><flux:button size="sm" variant="danger" wire:click="endSession(true)">{{ __('Abort') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('endingId', null)">{{ __('Cancel') }}</flux:button></div>
                </form>
            @endif

            <flux:heading level="3">{{ __('Recent sessions') }}</flux:heading>
            @foreach ($this->recent as $s)
                <div wire:key="r-{{ $s->id }}" class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800">
                    <span>{{ $s->patient->full_name }} · {{ $s->ended_at?->format('d M H:i') }} · {{ $s->status }}</span>
                    <span>{{ __('Wt loss') }} {{ $s->weightLossKg() ?? '—' }} kg · UF {{ $s->uf_achieved_ml ?? '—' }} ml</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
