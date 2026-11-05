<?php

use App\Models\Bed;
use App\Models\Ward;
use App\Services\Inpatient\AdmissionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Ward board')] class extends Component {
    #[Url]
    public ?int $ward = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['wards.view', 'nursing.tasks.manage']), 403);
    }

    #[Computed]
    public function wards()
    {
        return Ward::where('active', true)
            ->withCount(['beds', 'beds as occupied_count' => fn ($q) => $q->where('status', 'occupied'), 'beds as cleaning_count' => fn ($q) => $q->where('status', 'cleaning')])
            ->orderBy('name')->get();
    }

    #[Computed]
    public function beds()
    {
        return Bed::with(['ward', 'currentAdmission.patient', 'currentAdmission.doctor'])
            ->when($this->ward, fn ($q) => $q->where('ward_id', $this->ward))
            ->whereHas('ward', fn ($q) => $q->where('active', true))
            ->orderBy('ward_id')->orderBy('bed_number')
            ->get()
            ->groupBy('ward_id');
    }

    public function setBedStatus(int $id, string $status, AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->canAny(['wards.beds.allocate', 'nursing.tasks.manage']), 403);
        abort_unless(in_array($status, ['available', 'maintenance'], true), 422);

        $bed = Bed::findOrFail($id);

        if ($status === 'available') {
            $admissions->markBedAvailable($bed);
        } elseif ($bed->status === 'available') {
            $bed->update(['status' => 'maintenance']);
        }

        unset($this->beds, $this->wards);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="wards">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Ward board') }}</flux:heading>
        @canany(['wards.beds.allocate', 'wards.admissions.create'])
            <flux:button icon="arrow-right-end-on-rectangle" :href="route('wards.admissions')" wire:navigate>{{ __('Admissions desk') }}</flux:button>
        @endcanany
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
        <button type="button" wire:click="$set('ward', null)" class="rounded-xl border p-3 text-left {{ ! $ward ? 'border-indigo-500' : 'border-neutral-200 dark:border-neutral-700' }}">
            <div class="text-sm font-medium">{{ __('All wards') }}</div>
            <div class="text-xs text-neutral-500">{{ $this->wards->sum('occupied_count') }}/{{ $this->wards->sum('beds_count') }} {{ __('occupied') }}</div>
        </button>
        @foreach ($this->wards as $w)
            <button type="button" wire:key="wf-{{ $w->id }}" wire:click="$set('ward', {{ $w->id }})" class="rounded-xl border p-3 text-left {{ $ward === $w->id ? 'border-indigo-500' : 'border-neutral-200 dark:border-neutral-700' }}">
                <div class="text-sm font-medium">{{ $w->name }}</div>
                <div class="text-xs text-neutral-500">{{ $w->occupied_count }}/{{ $w->beds_count }} {{ __('occupied') }} @if ($w->cleaning_count) · {{ $w->cleaning_count }} {{ __('cleaning') }} @endif</div>
                <div class="mt-1 h-1.5 rounded bg-neutral-200 dark:bg-neutral-700"><div class="h-1.5 rounded bg-indigo-500" style="width: {{ $w->beds_count ? round($w->occupied_count / $w->beds_count * 100) : 0 }}%"></div></div>
            </button>
        @endforeach
    </div>

    @forelse ($this->beds as $wardId => $beds)
        <div wire:key="wb-{{ $wardId }}">
            <flux:heading level="3" class="mb-2">{{ $beds->first()->ward->name }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                @foreach ($beds as $bed)
                    @php
                        $style = ['available' => 'border-lime-400 bg-lime-50 dark:bg-lime-950/30', 'occupied' => 'border-indigo-400 bg-indigo-50 dark:bg-indigo-950/30', 'cleaning' => 'border-amber-400 bg-amber-50 dark:bg-amber-950/30', 'maintenance' => 'border-neutral-400 bg-neutral-100 dark:bg-neutral-800'][$bed->status] ?? '';
                        $adm = $bed->currentAdmission;
                    @endphp
                    <div wire:key="bed-{{ $bed->id }}" class="rounded-xl border p-3 text-sm {{ $style }}">
                        <div class="flex justify-between"><span class="font-semibold">{{ $bed->bed_number }}</span><span class="text-xs">{{ $bed->status }}</span></div>
                        @if ($adm)
                            <a href="{{ route('wards.admission', $adm) }}" wire:navigate class="mt-1 block hover:underline">
                                <div class="font-medium">{{ $adm->patient->full_name }}</div>
                                <div class="text-xs text-neutral-500">{{ $adm->patient->mrn }} · {{ __('Day') }} {{ $adm->lengthOfStayDays() }}</div>
                                <div class="text-xs text-neutral-500">{{ $adm->doctor?->name }}</div>
                            </a>
                        @elseif (in_array($bed->status, ['cleaning', 'maintenance']))
                            @canany(['wards.beds.allocate', 'nursing.tasks.manage'])
                                <flux:button size="xs" class="mt-2" wire:click="setBedStatus({{ $bed->id }}, 'available')">{{ __('Mark ready') }}</flux:button>
                            @endcanany
                        @elseif ($bed->status === 'available')
                            @canany(['wards.beds.allocate', 'nursing.tasks.manage'])
                                <flux:button size="xs" variant="ghost" class="mt-2" wire:click="setBedStatus({{ $bed->id }}, 'maintenance')">{{ __('Out of service') }}</flux:button>
                            @endcanany
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <flux:text>{{ __('No beds configured yet.') }}</flux:text>
    @endforelse
</div>
