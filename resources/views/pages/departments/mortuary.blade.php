<?php

use App\Models\Admission;
use App\Models\DeathNotification;
use App\Models\Patient;
use App\Services\Clinical\DepartmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Mortuary')] class extends Component {
    #[Url]
    public ?int $admission = null;

    public ?int $patientId = null;

    public string $patientSearch = '';

    public array $death = ['died_at' => '', 'place' => 'inpatient', 'immediate_cause' => '', 'immediate_icd' => '', 'underlying_cause' => '', 'underlying_icd' => '', 'other_conditions' => '', 'manner' => 'natural', 'is_maternal' => false, 'autopsy_requested' => false];

    public array $storage = [];

    public ?int $releasingId = null;

    public array $release = ['released_to' => '', 'released_to_id_number' => '', 'released_to_relationship' => '', 'burial_permit_number' => ''];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('mortuary.manage'), 403);
        $this->death['died_at'] = now()->format('Y-m-d\TH:i');

        if ($this->admission && ($a = Admission::find($this->admission))) {
            $this->patientId = $a->patient_id;
            $this->death['died_at'] = ($a->discharged_at ?? now())->format('Y-m-d\TH:i');
        }
    }

    #[Computed]
    public function patient(): ?Patient
    {
        return $this->patientId ? Patient::find($this->patientId) : null;
    }

    #[Computed]
    public function matches()
    {
        if (mb_strlen($this->patientSearch) < 2) {
            return collect();
        }
        $t = '%'.$this->patientSearch.'%';

        return Patient::whereNull('deceased_at')->where(fn ($q) => $q->where('first_name', 'like', $t)->orWhere('last_name', 'like', $t)->orWhere('mrn', 'like', $t)->orWhere('national_id', 'like', $t))->limit(8)->get();
    }

    #[Computed]
    public function bodies()
    {
        return DeathNotification::with('patient')->where('body_status', '!=', 'released')->orderBy('died_at')->get();
    }

    #[Computed]
    public function released()
    {
        return DeathNotification::with('patient')->where('body_status', 'released')->latest('released_at')->limit(20)->get();
    }

    public function recordDeath(DepartmentService $dept): void
    {
        abort_unless(Auth::user()->can('mortuary.manage'), 403);
        $this->validate([
            'death.died_at' => ['required', 'date'],
            'death.place' => ['required', 'in:'.implode(',', array_keys(DeathNotification::PLACES))],
            'death.immediate_cause' => ['required', 'string', 'max:255'],
            'death.immediate_icd' => ['nullable', 'string', 'max:20'],
            'death.underlying_cause' => ['nullable', 'string', 'max:255'],
            'death.underlying_icd' => ['nullable', 'string', 'max:20'],
            'death.other_conditions' => ['nullable', 'string', 'max:1000'],
            'death.manner' => ['required', 'in:'.implode(',', array_keys(DeathNotification::MANNERS))],
            'death.is_maternal' => ['boolean'],
            'death.autopsy_requested' => ['boolean'],
        ], [], ['death.immediate_cause' => 'immediate cause']);

        foreach (['immediate_icd', 'underlying_icd'] as $f) {
            if ($this->death[$f] !== '' && ! \App\Models\IcdCode::isValid($this->death[$f])) {
                $this->addError('death.'.$f, __('Not an ICD-11 code.'));

                return;
            }
        }

        abort_unless($this->patient, 422);
        $d = $dept->recordDeath($this->patient, $this->death, $this->admission ? Admission::find($this->admission) : null);
        $this->reset(['patientId', 'admission']);
        unset($this->bodies);
        Flux::toast(variant: 'success', text: __('Death notification :n recorded.', ['n' => $d->notification_number]));
    }

    public function admitBody(int $id, DepartmentService $dept): void
    {
        $this->validate(["storage.$id" => ['required', 'string', 'max:20']], [], ["storage.$id" => 'storage unit']);
        $dept->admitToMortuary(DeathNotification::findOrFail($id), strtoupper(trim($this->storage[$id])));
        unset($this->bodies);
    }

    public function releaseBody(DepartmentService $dept): void
    {
        $this->validate([
            'release.released_to' => ['required', 'string', 'max:150'],
            'release.released_to_id_number' => ['required', 'string', 'max:30'],
            'release.released_to_relationship' => ['required', 'string', 'max:60'],
            'release.burial_permit_number' => ['nullable', 'string', 'max:60'],
        ]);
        $dept->releaseBody(DeathNotification::findOrFail($this->releasingId), $this->release);
        $this->reset(['releasingId', 'release']);
        unset($this->bodies, $this->released);
        Flux::toast(variant: 'success', text: __('Body released. Storage charges posted.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Mortuary') }}</flux:heading>

    <div class="grid gap-6 xl:grid-cols-5">
        <form wire:submit="recordDeath" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-5 xl:col-span-2 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Record a death') }}</flux:heading>
            @if ($this->patient)
                <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">
                    <span>{{ $this->patient->full_name }} <span class="font-mono text-xs">{{ $this->patient->mrn }}</span></span>
                    <flux:button size="xs" variant="ghost" wire:click="$set('patientId', null)">{{ __('Change') }}</flux:button>
                </div>
            @else
                <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Deceased (registered patient)')" :placeholder="__('Name, MRN or ID')" />
                @foreach ($this->matches as $p)
                    <button type="button" wire:key="pm-{{ $p->id }}" wire:click="$set('patientId', {{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $p->full_name }} <span class="font-mono text-xs text-zinc-500">{{ $p->mrn }}</span></button>
                @endforeach
                <flux:text class="text-xs">{{ __('Brought-in-dead and unidentified bodies: register first (Emergency → unidentified).') }}</flux:text>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input type="datetime-local" wire:model="death.died_at" :label="__('Date & time of death')" />
                <flux:select wire:model="death.place" :label="__('Place of death')">
                    @foreach (DeathNotification::PLACES as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                </flux:select>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2"><flux:input wire:model="death.immediate_cause" :label="__('(a) Immediate cause')" /></div>
                <flux:input wire:model="death.immediate_icd" :label="__('ICD-11')" />
                <div class="sm:col-span-2"><flux:input wire:model="death.underlying_cause" :label="__('(b) Underlying cause')" /></div>
                <flux:input wire:model="death.underlying_icd" :label="__('ICD-11')" />
            </div>
            <flux:textarea wire:model="death.other_conditions" rows="2" :label="__('Other significant conditions')" />
            <flux:select wire:model="death.manner" :label="__('Manner of death')">
                @foreach (DeathNotification::MANNERS as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
            </flux:select>
            <div class="flex gap-4"><flux:checkbox wire:model="death.is_maternal" :label="__('Maternal death')" /><flux:checkbox wire:model="death.autopsy_requested" :label="__('Post-mortem requested')" /></div>
            @error('death')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            <div><flux:button type="submit" variant="primary" :disabled="! $this->patient">{{ __('Record death notification') }}</flux:button></div>
        </form>

        <div class="space-y-4 xl:col-span-3">
            <flux:heading level="3">{{ __('Bodies in care') }} ({{ $this->bodies->count() }})</flux:heading>
            @forelse ($this->bodies as $b)
                <div wire:key="b-{{ $b->id }}" class="space-y-2 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><span class="font-medium">{{ $b->patient->full_name }}</span> <span class="font-mono text-xs text-zinc-500">{{ $b->notification_number }}</span>
                            <div class="text-xs text-zinc-500">{{ __('Died') }} {{ $b->died_at->format('d M Y H:i') }} · {{ $b->immediate_cause }}</div></div>
                        <div class="flex items-center gap-2">
                            @if ($b->body_status === 'in_mortuary')<flux:badge size="sm" color="indigo">{{ __('Unit') }} {{ $b->storage_unit }} · {{ __('day') }} {{ $b->storageDays() }}</flux:badge>@else<flux:badge size="sm" color="amber">{{ __('Awaiting transfer') }}</flux:badge>@endif
                            <flux:button size="xs" icon="printer" :href="route('print.death', $b)" target="_blank">{{ __('Notification') }}</flux:button>
                        </div>
                    </div>
                    @if ($b->body_status === 'in_ward')
                        <div class="flex items-end gap-2"><flux:input wire:model="storage.{{ $b->id }}" size="sm" :placeholder="__('Storage unit, e.g. C3')" class="max-w-40" /><flux:button size="sm" wire:click="admitBody({{ $b->id }})">{{ __('Admit to mortuary') }}</flux:button></div>
                        @error('storage.'.$b->id)<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                        @error('storage_unit')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    @else
                        <flux:button size="sm" variant="primary" wire:click="$set('releasingId', {{ $b->id }})">{{ __('Release body') }}</flux:button>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('No bodies in care.') }}</flux:text>
            @endforelse

            @if ($releasingId)
                <form wire:submit="releaseBody" class="grid gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                    <flux:heading level="3">{{ __('Release to next of kin') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="release.released_to" :label="__('Received by (full name)')" />
                        <flux:input wire:model="release.released_to_id_number" :label="__('ID number')" />
                        <flux:input wire:model="release.released_to_relationship" :label="__('Relationship')" />
                        <flux:input wire:model="release.burial_permit_number" :label="__('Burial permit no.')" />
                    </div>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary" size="sm">{{ __('Confirm release') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('releasingId', null)">{{ __('Cancel') }}</flux:button></div>
                </form>
            @endif

            @if ($this->released->isNotEmpty())
                <flux:heading level="3">{{ __('Recently released') }}</flux:heading>
                @foreach ($this->released as $r)
                    <div wire:key="r-{{ $r->id }}" class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800"><span>{{ $r->patient->full_name }} → {{ $r->released_to }} ({{ $r->released_to_relationship }})</span><span>{{ $r->released_at->format('d M Y') }}</span></div>
                @endforeach
            @endif
        </div>
    </div>
</div>
