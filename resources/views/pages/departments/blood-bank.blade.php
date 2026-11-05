<?php

use App\Models\BloodIssue;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Patient;
use App\Services\Clinical\DepartmentService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Blood bank')] class extends Component {
    public array $unit = ['unit_number' => '', 'abo' => 'O', 'rh' => 'pos', 'component' => 'prbc', 'volume_ml' => '', 'collected_on' => '', 'expires_on' => '', 'source' => 'KNBTS'];

    public ?int $patientId = null;

    public string $patientSearch = '';

    public array $req = ['patient_abo' => '', 'patient_rh' => '', 'component' => 'prbc', 'units_requested' => '1', 'urgency' => 'routine', 'indication' => ''];

    public ?int $issuingId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['bloodbank.manage', 'bloodbank.request']), 403);
    }

    #[Computed]
    public function stock()
    {
        return BloodUnit::where('status', 'available')->where('expires_on', '>=', now()->toDateString())
            ->get()->groupBy(fn ($u) => $u->component.'|'.$u->group())->map->count();
    }

    #[Computed]
    public function expiring()
    {
        return BloodUnit::where('status', 'available')->whereBetween('expires_on', [now()->toDateString(), now()->addDays(5)->toDateString()])->orderBy('expires_on')->get();
    }

    #[Computed]
    public function requests()
    {
        return BloodRequest::with(['patient', 'issues.unit', 'requester'])->whereIn('status', ['pending', 'crossmatched'])->orderByRaw("case urgency when 'emergency' then 0 when 'urgent' then 1 else 2 end")->get();
    }

    #[Computed]
    public function matches()
    {
        if (mb_strlen($this->patientSearch) < 2) {
            return collect();
        }
        $t = '%'.$this->patientSearch.'%';

        return Patient::where(fn ($q) => $q->where('first_name', 'like', $t)->orWhere('last_name', 'like', $t)->orWhere('mrn', 'like', $t))->limit(8)->get();
    }

    public function receive(DepartmentService $dept): void
    {
        abort_unless(Auth::user()->can('bloodbank.manage'), 403);
        $data = $this->validate([
            'unit.unit_number' => ['required', 'string', 'max:40', Rule::unique('blood_units', 'unit_number')->where('tenant_id', app(TenantContext::class)->id())],
            'unit.abo' => ['required', 'in:A,B,AB,O'],
            'unit.rh' => ['required', 'in:pos,neg'],
            'unit.component' => ['required', 'in:'.implode(',', array_keys(BloodUnit::COMPONENTS))],
            'unit.volume_ml' => ['nullable', 'integer', 'between:10,600'],
            'unit.collected_on' => ['nullable', 'date', 'before_or_equal:today'],
            'unit.expires_on' => ['required', 'date', 'after:today'],
            'unit.source' => ['required', 'string', 'max:60'],
        ], [], ['unit.unit_number' => 'unit number'])['unit'];

        $dept->receiveUnit(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['tenant_id' => app(TenantContext::class)->id()]);
        $this->unit = ['unit_number' => '', 'abo' => 'O', 'rh' => 'pos', 'component' => 'prbc', 'volume_ml' => '', 'collected_on' => '', 'expires_on' => '', 'source' => 'KNBTS'];
        unset($this->stock, $this->expiring);
        Flux::toast(variant: 'success', text: __('Unit received into stock.'));
    }

    public function requestBlood(DepartmentService $dept): void
    {
        abort_unless(Auth::user()->canAny(['bloodbank.request', 'bloodbank.manage']), 403);
        $this->validate([
            'req.patient_abo' => ['nullable', 'in:A,B,AB,O'],
            'req.patient_rh' => ['nullable', 'in:pos,neg'],
            'req.component' => ['required', 'in:'.implode(',', array_keys(BloodUnit::COMPONENTS))],
            'req.units_requested' => ['required', 'integer', 'between:1,10'],
            'req.urgency' => ['required', 'in:routine,urgent,emergency'],
            'req.indication' => ['required', 'string', 'max:255'],
        ]);
        abort_unless($this->patientId, 422);
        $r = $dept->requestBlood(Patient::findOrFail($this->patientId), $this->req);
        $this->reset(['patientId', 'patientSearch']);
        $this->req = ['patient_abo' => '', 'patient_rh' => '', 'component' => 'prbc', 'units_requested' => '1', 'urgency' => 'routine', 'indication' => ''];
        unset($this->requests);
        Flux::toast(variant: 'success', text: __('Request :n sent to the blood bank.', ['n' => $r->request_number]));
    }

    public function setGroup(int $requestId, string $abo, string $rh): void
    {
        abort_unless(Auth::user()->can('bloodbank.manage'), 403);
        abort_unless(in_array($abo, ['A', 'B', 'AB', 'O'], true) && in_array($rh, ['pos', 'neg'], true), 422);
        BloodRequest::findOrFail($requestId)->update(['patient_abo' => $abo, 'patient_rh' => $rh]);
        unset($this->requests);
    }

    public function issue(int $requestId, int $unitId, DepartmentService $dept): void
    {
        abort_unless(Auth::user()->can('bloodbank.manage'), 403);
        $dept->issueUnit(BloodRequest::findOrFail($requestId), BloodUnit::findOrFail($unitId), 'compatible');
        unset($this->requests, $this->stock, $this->expiring);
        Flux::toast(variant: 'success', text: __('Unit issued.'));
    }

    public function reaction(int $issueId, string $reaction, DepartmentService $dept): void
    {
        abort_unless(in_array($reaction, ['none', 'mild', 'severe'], true), 422);
        $dept->recordReaction(BloodIssue::findOrFail($issueId), $reaction, null);
        unset($this->requests);
    }

    public function discard(int $unitId, DepartmentService $dept): void
    {
        abort_unless(Auth::user()->can('bloodbank.manage'), 403);
        $dept->discardUnit(BloodUnit::findOrFail($unitId), 'Expiring / quality');
        unset($this->stock, $this->expiring);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Blood bank') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-4 xl:grid-cols-8">
        @foreach (['A+', 'A−', 'B+', 'B−', 'AB+', 'AB−', 'O+', 'O−'] as $g)
            @php($n = $this->stock->filter(fn ($c, $k) => str_ends_with($k, '|'.$g))->sum())
            <div class="rounded-xl border border-neutral-200 p-3 text-center dark:border-neutral-700"><div class="text-xs text-zinc-500">{{ $g }}</div><div class="text-2xl font-semibold {{ $n === 0 ? 'text-red-600' : '' }}">{{ $n }}</div><div class="text-[0.65rem] text-zinc-400">{{ __('units') }}</div></div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="space-y-4">
            <flux:heading level="3">{{ __('Requests') }}</flux:heading>
            @forelse ($this->requests as $r)
                <div wire:key="rq-{{ $r->id }}" class="space-y-2 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                    <div class="flex flex-wrap justify-between gap-2">
                        <div><span class="font-medium">{{ $r->patient->full_name }}</span> <span class="font-mono text-xs text-zinc-500">{{ $r->request_number }}</span>
                            <div class="text-xs text-zinc-500">{{ $r->units_requested }} × {{ \App\Models\BloodUnit::COMPONENTS[$r->component] }} · {{ $r->indication }} · {{ $r->requester?->name }}</div></div>
                        <flux:badge size="sm" :color="match($r->urgency) { 'emergency' => 'red', 'urgent' => 'amber', default => 'zinc' }">{{ $r->urgency }}</flux:badge>
                    </div>
                    @if (! $r->patient_abo || ! $r->patient_rh)
                        @can('bloodbank.manage')
                            <div class="flex flex-wrap gap-1 text-xs">{{ __('Group the patient:') }}
                                @foreach (['A', 'B', 'AB', 'O'] as $abo)@foreach (['pos', 'neg'] as $rh)<flux:button size="xs" wire:click="setGroup({{ $r->id }}, '{{ $abo }}', '{{ $rh }}')">{{ $abo }}{{ $rh === 'pos' ? '+' : '−' }}</flux:button>@endforeach @endforeach
                            </div>
                        @endcan
                    @else
                        <div class="text-xs">{{ __('Patient group') }}: <strong>{{ $r->patient_abo }}{{ $r->patient_rh === 'pos' ? '+' : '−' }}</strong> · {{ __('issued') }} {{ $r->issues->count() }}/{{ $r->units_requested }}</div>
                        @can('bloodbank.manage')
                            @php($units = app(\App\Services\Clinical\DepartmentService::class)->compatibleUnits($r)->take(5))
                            <div class="flex flex-wrap gap-1">
                                @forelse ($units as $u)
                                    <flux:button size="xs" wire:click="issue({{ $r->id }}, {{ $u->id }})" wire:confirm="{{ __('Crossmatch compatible — issue unit :u?', ['u' => $u->unit_number]) }}">{{ $u->unit_number }} ({{ $u->group() }}, {{ __('exp') }} {{ $u->expires_on->format('d M') }})</flux:button>
                                @empty
                                    <span class="text-xs text-red-600">{{ __('No compatible units in stock — order from KNBTS.') }}</span>
                                @endforelse
                            </div>
                        @endcan
                    @endif
                    @foreach ($r->issues as $is)
                        <div class="flex items-center justify-between text-xs"><span>{{ $is->unit->unit_number }} {{ __('issued') }} {{ $is->issued_at->format('d M H:i') }}</span>
                            <span>{{ __('Reaction') }}: @foreach (['none', 'mild', 'severe'] as $x)<button type="button" wire:click="reaction({{ $is->id }}, '{{ $x }}')" class="ml-1 rounded px-1.5 {{ $is->reaction === $x ? 'bg-indigo-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800' }}">{{ $x }}</button>@endforeach</span></div>
                    @endforeach
                </div>
            @empty
                <flux:text>{{ __('No open requests.') }}</flux:text>
            @endforelse

            <form wire:submit="requestBlood" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('New request') }}</flux:heading>
                @if ($patientId)
                    <div class="flex justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800"><span>{{ \App\Models\Patient::find($patientId)?->full_name }}</span><flux:button size="xs" variant="ghost" wire:click="$set('patientId', null)">{{ __('Change') }}</flux:button></div>
                @else
                    <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Patient')" :placeholder="__('Name or MRN')" />
                    @foreach ($this->matches as $p)<button type="button" wire:key="m-{{ $p->id }}" wire:click="$set('patientId', {{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $p->full_name }} <span class="font-mono text-xs">{{ $p->mrn }}</span></button>@endforeach
                @endif
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:select wire:model="req.component" :label="__('Component')" class="sm:col-span-2">@foreach (\App\Models\BloodUnit::COMPONENTS as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach</flux:select>
                    <flux:input type="number" wire:model="req.units_requested" :label="__('Units')" />
                    <flux:select wire:model="req.urgency" :label="__('Urgency')"><flux:select.option value="routine">{{ __('Routine') }}</flux:select.option><flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option><flux:select.option value="emergency">{{ __('Emergency') }}</flux:select.option></flux:select>
                </div>
                <flux:input wire:model="req.indication" :label="__('Indication (e.g. Hb 5.8, PPH)')" />
                <div><flux:button type="submit" size="sm" variant="primary" :disabled="! $patientId">{{ __('Send request') }}</flux:button></div>
            </form>
        </div>

        @can('bloodbank.manage')
            <div class="space-y-4">
                <form wire:submit="receive" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Receive unit') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="sm:col-span-2"><flux:input wire:model="unit.unit_number" :label="__('Unit number')" /></div>
                        <flux:select wire:model="unit.abo" :label="__('ABO')">@foreach (['A', 'B', 'AB', 'O'] as $g)<flux:select.option value="{{ $g }}">{{ $g }}</flux:select.option>@endforeach</flux:select>
                        <flux:select wire:model="unit.rh" :label="__('Rh')"><flux:select.option value="pos">+</flux:select.option><flux:select.option value="neg">−</flux:select.option></flux:select>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <flux:select wire:model="unit.component" :label="__('Component')" class="sm:col-span-2">@foreach (\App\Models\BloodUnit::COMPONENTS as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach</flux:select>
                        <flux:input type="number" wire:model="unit.volume_ml" :label="__('Volume ml')" />
                        <flux:input wire:model="unit.source" :label="__('Source')" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input type="date" wire:model="unit.collected_on" :label="__('Collected')" />
                        <flux:input type="date" wire:model="unit.expires_on" :label="__('Expires')" />
                    </div>
                    @error('expires_on')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    <div><flux:button type="submit" size="sm" variant="primary">{{ __('Receive') }}</flux:button></div>
                </form>

                <flux:heading level="3">{{ __('Expiring within 5 days') }}</flux:heading>
                @forelse ($this->expiring as $u)
                    <div wire:key="ex-{{ $u->id }}" class="flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2 text-xs dark:bg-amber-950">
                        <span>{{ $u->unit_number }} · {{ $u->group() }} · {{ \App\Models\BloodUnit::COMPONENTS[$u->component] }} · {{ __('exp') }} {{ $u->expires_on->format('d M') }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="discard({{ $u->id }})" wire:confirm="{{ __('Discard this unit?') }}">{{ __('Discard') }}</flux:button>
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('Nothing expiring soon.') }}</flux:text>
                @endforelse
            </div>
        @endcan
    </div>
</div>
