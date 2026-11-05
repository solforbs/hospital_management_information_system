<?php

use App\Models\Patient;
use App\Models\SpecialtyVisit;
use App\Services\Clinical\DepartmentService;
use App\Support\SpecialtyClinics;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Specialist clinic')] class extends Component {
    #[Locked]
    public string $clinic = '';

    public ?int $patientId = null;

    public string $patientSearch = '';

    public array $data = [];

    public string $notes = '';

    public ?string $next_visit_on = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('clinics.manage'), 403);
        // Route names are clinics.<key> (one route per clinic).
        $this->clinic = Str::after((string) request()->route()?->getName(), 'clinics.');
        abort_unless(SpecialtyClinics::find($this->clinic), 404);
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->data = array_fill_keys(array_keys(SpecialtyClinics::find($this->clinic)['fields']), '');
        $this->notes = '';
        $this->next_visit_on = null;
    }

    public function definition(): array
    {
        return SpecialtyClinics::find($this->clinic);
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
    public function history()
    {
        return $this->patientId ? SpecialtyVisit::with('clinician')->where('clinic', $this->clinic)->where('patient_id', $this->patientId)->latest()->limit(10)->get() : collect();
    }

    #[Computed]
    public function today()
    {
        return SpecialtyVisit::with('patient')->where('clinic', $this->clinic)->whereDate('created_at', today())->latest()->get();
    }

    #[Computed]
    public function upcoming()
    {
        return SpecialtyVisit::with('patient')->where('clinic', $this->clinic)->whereBetween('next_visit_on', [today()->toDateString(), today()->addDays(7)->toDateString()])->orderBy('next_visit_on')->get();
    }

    public function save(DepartmentService $dept): void
    {
        abort_unless(Auth::user()->can('clinics.manage'), 403);
        $this->validate(SpecialtyClinics::rules($this->clinic) + [
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_visit_on' => ['nullable', 'date', 'after:today'],
        ]);
        abort_unless($this->patientId, 422);

        $dept->recordClinicVisit($this->clinic, Patient::findOrFail($this->patientId), $this->data, $this->notes, $this->next_visit_on);
        $this->resetForm();
        unset($this->history, $this->today, $this->upcoming);
        Flux::toast(variant: 'success', text: __('Visit recorded.'));
    }
};
?>

@php
    $def = $this->definition();
@endphp
<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __($def['label']) }}</flux:heading>

    <div class="grid gap-6 xl:grid-cols-5">
        <form wire:submit="save" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-5 xl:col-span-3 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Clinic visit') }}</flux:heading>
            @if ($patientId)
                <div class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800"><span>{{ \App\Models\Patient::find($patientId)?->full_name }} <span class="font-mono text-xs">{{ \App\Models\Patient::find($patientId)?->mrn }}</span></span><flux:button size="xs" variant="ghost" wire:click="$set('patientId', null)">{{ __('Change') }}</flux:button></div>
            @else
                <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Patient')" :placeholder="__('Name or MRN')" />
                @foreach ($this->matches as $p)<button type="button" wire:key="m-{{ $p->id }}" wire:click="$set('patientId', {{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $p->full_name }} <span class="font-mono text-xs">{{ $p->mrn }}</span></button>@endforeach
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($def['fields'] as $key => $f)
                    <div wire:key="f-{{ $key }}" class="{{ $f[1] === 'textarea' ? 'sm:col-span-2' : '' }}">
                        @if ($f[1] === 'select')
                            <flux:select wire:model="data.{{ $key }}" :label="__($f[0])">
                                <flux:select.option value="">—</flux:select.option>
                                @foreach ($f[2] as $o)<flux:select.option value="{{ $o }}">{{ ucfirst(str_replace('_', ' ', $o)) }}</flux:select.option>@endforeach
                            </flux:select>
                        @elseif ($f[1] === 'textarea')
                            <flux:textarea wire:model="data.{{ $key }}" rows="2" :label="__($f[0])" />
                        @else
                            <flux:input wire:model="data.{{ $key }}" :type="$f[1] === 'number' ? 'number' : ($f[1] === 'date' ? 'date' : 'text')" step="any" :label="__($f[0])" />
                        @endif
                    </div>
                @endforeach
            </div>
            <flux:textarea wire:model="notes" rows="3" :label="__('Notes / plan')" />
            <flux:input type="date" wire:model="next_visit_on" :label="__('Next visit')" class="max-w-xs" />
            <flux:text class="text-xs">{{ __('The clinic fee is charged to the patient\'s open visit (check them in at reception first).') }}</flux:text>
            <div><flux:button type="submit" variant="primary" :disabled="! $patientId">{{ __('Save visit') }}</flux:button></div>

            @if ($this->history->isNotEmpty())
                <flux:heading level="3">{{ __('Previous visits') }}</flux:heading>
                @foreach ($this->history as $h)
                    <div wire:key="h-{{ $h->id }}" class="rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800">
                        <div class="font-medium">{{ $h->created_at->format('d M Y') }} · {{ $h->clinician?->name }}</div>
                        @foreach ($h->data ?? [] as $k => $v)<span class="me-3"><span class="text-zinc-500">{{ __($def['fields'][$k][0] ?? $k) }}:</span> {{ $v }}</span>@endforeach
                        @if ($h->notes)<div class="mt-1">{{ $h->notes }}</div>@endif
                    </div>
                @endforeach
            @endif
        </form>

        <div class="space-y-4 xl:col-span-2">
            <flux:heading level="3">{{ __('Seen today') }} ({{ $this->today->count() }})</flux:heading>
            @forelse ($this->today as $v)
                <div wire:key="t-{{ $v->id }}" class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">{{ $v->patient->full_name }} <span class="text-xs text-zinc-500">{{ $v->created_at->format('H:i') }}</span></div>
            @empty
                <flux:text class="text-sm">{{ __('No visits yet today.') }}</flux:text>
            @endforelse
            <flux:heading level="3">{{ __('Booked in the next 7 days') }}</flux:heading>
            @forelse ($this->upcoming as $v)
                <div wire:key="u-{{ $v->id }}" class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800"><span>{{ $v->patient->full_name }}</span><span class="text-xs">{{ $v->next_visit_on->format('D d M') }}</span></div>
            @empty
                <flux:text class="text-sm">{{ __('None booked.') }}</flux:text>
            @endforelse
        </div>
    </div>
</div>
