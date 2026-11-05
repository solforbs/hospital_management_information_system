<?php

use App\Models\Encounter;
use App\Models\LabOrderItem;
use App\Models\RadiologyOrder;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Doctor worklist')] class extends Component {
    #[Url]
    public string $scope = 'mine';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('encounters.create'), 403);
    }

    #[Computed]
    public function waiting()
    {
        return Encounter::with(['patient', 'latestVitals', 'doctor'])
            ->where('status', 'open')
            ->whereIn('stage', [EncounterStage::WAITING_DOCTOR, EncounterStage::IN_CONSULTATION, EncounterStage::AWAITING_RESULTS])
            ->when($this->scope === 'mine', fn ($q) => $q->where(fn ($w) => $w->where('attending_doctor_id', Auth::id())->orWhereNull('attending_doctor_id')))
            ->orderByRaw("case priority when 'emergency' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('started_at')
            ->get();
    }

    #[Computed]
    public function admitted()
    {
        return Encounter::with(['patient', 'admissions' => fn ($q) => $q->where('status', 'admitted')->with(['ward', 'bed'])])
            ->where('status', 'open')
            ->where('stage', EncounterStage::ADMITTED)
            ->when($this->scope === 'mine', fn ($q) => $q->where('attending_doctor_id', Auth::id()))
            ->get();
    }

    #[Computed]
    public function newResults()
    {
        $labs = LabOrderItem::with(['test', 'order.patient'])
            ->where('status', 'released')
            ->where('released_at', '>=', now()->subDay())
            ->whereHas('order', fn ($q) => $q->where('ordered_by', Auth::id()))
            ->latest('released_at')->limit(10)->get();

        $imaging = RadiologyOrder::with(['exam', 'patient'])
            ->where('status', 'verified')
            ->where('verified_at', '>=', now()->subDay())
            ->where('ordered_by', Auth::id())
            ->latest('verified_at')->limit(10)->get();

        return compact('labs', 'imaging');
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="queue,consultation,lab,radiology,wards">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Doctor worklist') }}</flux:heading>
        <div class="flex gap-2">
            <flux:button size="sm" :variant="$scope === 'mine' ? 'primary' : 'ghost'" wire:click="$set('scope', 'mine')">{{ __('My patients') }}</flux:button>
            <flux:button size="sm" :variant="$scope === 'all' ? 'primary' : 'ghost'" wire:click="$set('scope', 'all')">{{ __('All') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <flux:heading level="3" class="mb-2">{{ __('Outpatient queue') }} ({{ $this->waiting->count() }})</flux:heading>
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-left text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr><th class="px-3 py-2">#</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Triage') }}</th><th class="px-3 py-2">{{ __('Vitals') }}</th><th class="px-3 py-2">{{ __('Stage') }}</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse ($this->waiting as $e)
                            <tr wire:key="w-{{ $e->id }}">
                                <td class="px-3 py-2 font-semibold">{{ $e->queue_number }}</td>
                                <td class="px-3 py-2">
                                    {{ $e->patient->full_name }}
                                    <div class="text-xs text-neutral-500">{{ $e->patient->mrn }} · {{ ucfirst($e->patient->sex) }} {{ $e->patient->age }} · {{ $e->chief_complaint }}</div>
                                </td>
                                <td class="px-3 py-2"><x-triage-badge :category="$e->triage_category" /></td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($v = $e->latestVitals)
                                        BP {{ $v->bloodPressure() ?? '—' }} · HR {{ $v->pulse ?? '—' }} · T {{ $v->temperature ?? '—' }}
                                    @else — @endif
                                </td>
                                <td class="px-3 py-2"><flux:badge size="sm" :color="EncounterStage::color($e->stage)">{{ EncounterStage::label($e->stage) }}</flux:badge></td>
                                <td class="px-3 py-2 text-right"><flux:button size="xs" variant="primary" :href="route('consultation.show', $e)" wire:navigate>{{ $e->stage === EncounterStage::WAITING_DOCTOR ? __('Call in') : __('Open') }}</flux:button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No patients waiting.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <flux:heading level="3" class="mb-2 mt-6">{{ __('Inpatients') }} ({{ $this->admitted->count() }})</flux:heading>
            <div class="grid gap-2 md:grid-cols-2">
                @forelse ($this->admitted as $e)
                    @php $adm = $e->admissions->first(); @endphp
                    <a wire:key="ip-{{ $e->id }}" href="{{ route('consultation.show', $e) }}" wire:navigate class="rounded-lg border border-neutral-200 p-3 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                        <div class="font-medium">{{ $e->patient->full_name }}</div>
                        <div class="text-xs text-neutral-500">{{ $adm?->ward?->name }} · {{ __('Bed') }} {{ $adm?->bed?->bed_number }} · {{ __('Day') }} {{ $adm?->lengthOfStayDays() }}</div>
                    </a>
                @empty
                    <flux:text>{{ __('No admitted patients.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div>
            <flux:heading level="3" class="mb-2">{{ __('Results released (24h)') }}</flux:heading>
            <div class="space-y-2">
                @forelse ($this->newResults['labs'] as $r)
                    <a wire:key="lr-{{ $r->id }}" href="{{ route('consultation.show', $r->order->encounter_id) }}" wire:navigate class="block rounded-lg border p-3 text-sm {{ $r->flag === 'critical' ? 'border-red-500 bg-red-50 dark:bg-red-950/40' : 'border-neutral-200 dark:border-neutral-700' }}">
                        <div class="flex justify-between"><span class="font-medium">{{ $r->test->name }}</span>@if ($r->flag && $r->flag !== 'normal')<flux:badge size="sm" :color="$r->flag === 'critical' ? 'red' : 'amber'">{{ $r->flag }}</flux:badge>@endif</div>
                        <div class="text-xs text-neutral-500">{{ $r->order->patient->full_name }} · {{ $r->result_value }} {{ $r->result_unit }}</div>
                    </a>
                @empty
                @endforelse
                @foreach ($this->newResults['imaging'] as $r)
                    <a wire:key="ir-{{ $r->id }}" href="{{ route('consultation.show', $r->encounter_id) }}" wire:navigate class="block rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="font-medium">{{ $r->exam->name }}</div>
                        <div class="text-xs text-neutral-500">{{ $r->patient->full_name }} · {{ str($r->impression)->limit(60) }}</div>
                    </a>
                @endforeach
                @if ($this->newResults['labs']->isEmpty() && $this->newResults['imaging']->isEmpty())
                    <flux:text>{{ __('No new results.') }}</flux:text>
                @endif
            </div>
        </div>
    </div>
</div>
